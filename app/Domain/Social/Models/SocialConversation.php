<?php

namespace App\Domain\Social\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Leads\Models\Lead;
use App\Domain\Social\Enums\ConversationStatus;
use App\Domain\Social\Enums\SocialChannel;
use App\Domain\Social\MessagingWindow;
use App\Domain\Social\Referrals\ClickToMessageReferral;
use App\Models\User;
use Database\Factories\SocialConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One thread with one person, on whichever channel they used.
 *
 * **No `ScopesByAccessLevel`, deliberately.** A shared inbox is shared: the
 * queue of unanswered messages has to be visible to everyone who might answer
 * it, and record-level scoping would hide exactly the conversations nobody has
 * claimed yet — an inbox where unanswered threads are invisible until somebody
 * claims them is an inbox where nothing gets answered. Who may open it is the
 * `social.inbox.view` permission, and assignment routes work rather than
 * restricting sight of it.
 *
 * The records it points at are scoped on their own, which is where customer
 * visibility belongs: `subject()` returns a lead or a contact, and every screen
 * that renders one goes through that record's own policy.
 *
 * @property int $id
 * @property string $channel
 * @property string $external_conversation_id
 * @property string|null $channel_account_id
 * @property string|null $participant_external_id
 * @property string|null $participant_name
 * @property string|null $participant_handle
 * @property int|null $lead_id
 * @property int|null $contact_id
 * @property int|null $assigned_to_id
 * @property string $status
 * @property int $unread_count
 * @property Carbon|null $last_message_at
 * @property Carbon|null $window_expires_at
 */
class SocialConversation extends Model
{
    /** @use HasFactory<SocialConversationFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * `status`, `unread_count` and `window_expires_at` are absent: the actions
     * own them. Nothing that records a message may declare that somebody has
     * read it, and nothing but an inbound message may open Meta's window.
     *
     * @var list<string>
     */
    protected $fillable = [
        'channel',
        'external_conversation_id',
        'channel_account_id',
        'participant_external_id',
        'participant_name',
        'participant_handle',
        'lead_id',
        'contact_id',
        'assigned_to_id',
        'last_message_at',
    ];

    /**
     * `channel()`, `status()` and `referral()` are named after their columns, so
     * every one of those keys must be present on every instance — see
     * .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'channel' => SocialChannel::Messenger->value,
        'status' => ConversationStatus::Open->value,
        'unread_count' => 0,
        'referral' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unread_count' => 'integer',
            'referral' => 'array',
            'last_message_at' => 'datetime',
            'window_expires_at' => 'datetime',
        ];
    }

    /**
     * Only what a person decided.
     *
     * A conversation's other columns move on every message that arrives —
     * `last_message_at`, `unread_count`, `window_expires_at` — and logging those
     * would bury the entries that matter under one row per delivery, in the one
     * screen somebody opens to find out who did what.
     *
     * What is left is the audit trail people actually ask for: who took the
     * conversation, who closed it, and which record it was attached to.
     *
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return ['status', 'assigned_to_id', 'lead_id', 'contact_id'];
    }

    /**
     * @return HasMany<SocialMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SocialMessage::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    /**
     * The advertisement this conversation started from, if it did.
     *
     * Named after its own column, so it carries a default in `$attributes` and
     * reads through `getAttributeValue()` — see
     * .ai/rules/models-name-collisions.md for what happens otherwise.
     */
    public function referral(): ?ClickToMessageReferral
    {
        $referral = $this->getAttributeValue('referral');

        return ClickToMessageReferral::fromPayload(is_array($referral) ? $referral : null);
    }

    public function channel(): SocialChannel
    {
        return SocialChannel::tryFrom((string) $this->getAttributeValue('channel')) ?? SocialChannel::Messenger;
    }

    public function status(): ConversationStatus
    {
        return ConversationStatus::tryFrom((string) $this->getAttributeValue('status')) ?? ConversationStatus::Open;
    }

    /**
     * What to call the person, when they have not given a name.
     */
    public function displayName(): string
    {
        foreach ([$this->participant_name, $this->participant_handle] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return $this->channel()->label().' user';
    }

    /**
     * Whether a free-form reply is allowed right now.
     */
    public function isWindowOpen(?Carbon $now = null): bool
    {
        return MessagingWindow::isOpen($this->window_expires_at, $now);
    }

    /**
     * Meta's reason for refusing a reply, or null when one is allowed.
     */
    public function windowRefusal(?Carbon $now = null): ?string
    {
        return MessagingWindow::refusal($this->channel(), $this->window_expires_at, $now);
    }

    public function windowRemaining(?Carbon $now = null): ?string
    {
        return MessagingWindow::remaining($this->window_expires_at, $now);
    }

    /**
     * Whether this thread has been tied to anything in the CRM.
     *
     * The question the right-hand panel opens with: an untied conversation
     * offers "create a lead", a tied one offers the record.
     */
    public function isLinked(): bool
    {
        return $this->lead_id !== null || $this->contact_id !== null;
    }

    /**
     * The CRM record this conversation is about, if there is one.
     *
     * The contact wins where both are set: a lead that has been converted is
     * somebody the company has decided to keep, and the contact is the record
     * people actually work from afterwards.
     */
    public function subject(): Lead|Contact|null
    {
        return $this->contact ?? $this->lead;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOnChannel(Builder $query, SocialChannel $channel): Builder
    {
        return $query->where($query->qualifyColumn('channel'), $channel->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithStatus(Builder $query, ConversationStatus $status): Builder
    {
        return $query->where($query->qualifyColumn('status'), $status->value);
    }

    /**
     * Waiting for somebody to pick it up.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('assigned_to_id'));
    }

    /**
     * Newest activity first, then by id — two conversations touched in the same
     * second otherwise come back in whatever order the engine chose, which makes
     * paging repeat or skip rows.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query
            ->orderByDesc($query->qualifyColumn('last_message_at'))
            ->orderByDesc($query->qualifyColumn('id'));
    }
}
