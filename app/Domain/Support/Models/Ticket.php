<?php

namespace App\Domain\Support\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Contacts\Models\Contact;
use App\Domain\CustomFields\Concerns\HasCustomFields;
use App\Domain\Shared\Concerns\ScopesByAccessLevel;
use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketSource;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\TicketFields;
use App\Domain\Timeline\Concerns\HasTimeline;
use App\Models\User;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A customer's problem, and what is being done about it.
 *
 * @property int $id
 * @property string|null $number
 * @property string $subject
 * @property string|null $description
 * @property string $status
 * @property int $priority
 * @property string $source
 * @property int|null $contact_id
 * @property int|null $account_id
 * @property int $owner_id
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 */
class Ticket extends Model
{
    use HasCustomFields;

    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    use HasTimeline;
    use RecordsActivity;
    use ScopesByAccessLevel;
    use SoftDeletes;

    /**
     * The prefix on every ticket reference. Short, and obviously a ticket when
     * a customer reads it out over the telephone.
     */
    public const PREFIX = 'TKT-';

    /**
     * `status` is absent on purpose: ChangeTicketStatusAction is the only
     * writer of it, the way MoveDealStageAction owns a deal's stage. So are
     * `number`, which is derived, and the two stamps, which that action owns.
     *
     * @var list<string>
     */
    protected $fillable = [
        'subject',
        'description',
        'priority',
        'source',
        'contact_id',
        'account_id',
        'owner_id',
    ];

    /**
     * Columns that share a name with a method on this model.
     *
     * See .ai/rules/models-name-collisions.md: without a default, an instance
     * created without that column turns the property read into a relation
     * lookup and calls the method.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'new',
        'priority' => 2,
        'source' => 'manual',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * The reference is derived from the id, so it cannot collide.
     *
     * Written in `created` rather than `creating` because the id does not exist
     * until the row does. That costs one extra UPDATE per ticket, which is the
     * price of a number that is unique without a sequence table and without the
     * race a `max(id) + 1` would carry — two agents raising a ticket in the same
     * second is not a rare event in a support queue.
     */
    protected static function booted(): void
    {
        static::created(function (self $ticket) {
            if ($ticket->number === null) {
                $ticket->forceFill(['number' => self::PREFIX.str_pad((string) $ticket->id, 5, '0', STR_PAD_LEFT)])->saveQuietly();
            }
        });
    }

    /**
     * An explicit allowlist, never logAll().
     *
     * @return array<int, string>
     */
    protected function activityAttributes(): array
    {
        return [
            'subject', 'status', 'priority', 'source',
            'contact_id', 'account_id', 'owner_id', 'resolved_at', 'closed_at',
        ];
    }

    // -- Relations ----------------------------------------------------------

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * The agent it is assigned to.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The conversation, oldest first — a thread is read downwards.
     *
     * @return HasMany<TicketComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class)->oldest('id');
    }

    /**
     * Colleagues following this ticket who are not assigned to it.
     *
     * @return BelongsToMany<User, $this>
     */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ticket_watchers')->withTimestamps();
    }

    public function isWatchedBy(User $user): bool
    {
        return $this->watchers()->whereKey($user->id)->exists();
    }

    // -- Presentation --------------------------------------------------------

    public function displayName(): string
    {
        return $this->reference().' '.$this->subject;
    }

    /**
     * The number, or a stand-in for a ticket that has not been saved yet.
     */
    public function reference(): string
    {
        return $this->number ?? self::PREFIX.'?????';
    }

    /**
     * getAttributeValue, not $this->status: the method and the column share a
     * name, so a property read on an instance without that attribute loaded
     * would be taken for a relation and call this method again.
     */
    public function status(): TicketStatus
    {
        return TicketStatus::tryFrom((string) $this->getAttributeValue('status')) ?? TicketStatus::New;
    }

    public function priority(): TicketPriority
    {
        return TicketPriority::tryFrom((int) $this->getAttributeValue('priority')) ?? TicketPriority::Normal;
    }

    public function source(): TicketSource
    {
        return TicketSource::tryFrom((string) $this->getAttributeValue('source')) ?? TicketSource::Manual;
    }

    public function isOpen(): bool
    {
        return $this->status()->isOpen();
    }

    public function isResolved(): bool
    {
        return $this->status() === TicketStatus::Resolved;
    }

    public function isClosed(): bool
    {
        return $this->status() === TicketStatus::Closed;
    }

    /**
     * How long it took to resolve, in hours, or null while it is still open.
     */
    public function hoursToResolve(): ?float
    {
        if ($this->resolved_at === null || $this->created_at === null) {
            return null;
        }

        return round((float) $this->created_at->diffInMinutes($this->resolved_at) / 60, 1);
    }

    /**
     * How long it has been sitting, in hours.
     *
     * Measured to the resolution when there is one, so an old ticket that was
     * dealt with quickly does not read as having been open for months.
     */
    public function ageInHours(): float
    {
        if ($this->created_at === null) {
            return 0.0;
        }

        return round((float) $this->created_at->diffInMinutes($this->resolved_at ?? now()) / 60, 1);
    }

    // -- Queries -------------------------------------------------------------

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('status'), TicketStatus::openValues());
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeWithStatus(Builder $query, TicketStatus $status): Builder
    {
        return $query->where($query->qualifyColumn('status'), $status->value);
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        if ($term === '') {
            return $query;
        }

        // The same columns the list screen searches, and deliberately only
        // columns on this table — see .ai/rules/accounts.md on why a model
        // scope that reaches into a relation makes a queued export match rows
        // the list never showed.
        return $query->where(function (Builder $inner) use ($term) {
            foreach (TicketFields::searchColumns() as $column) {
                $inner->orWhere($inner->qualifyColumn($column), 'like', '%'.$term.'%');
            }
        });
    }
}
