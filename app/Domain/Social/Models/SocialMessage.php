<?php

namespace App\Domain\Social\Models;

use App\Domain\Social\Enums\MessageDirection;
use App\Domain\Social\Enums\MessageStatus;
use App\Domain\Social\Enums\MessageType;
use App\Models\User;
use Database\Factories\SocialMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing somebody said.
 *
 * Append-only in spirit. A message is a record of what was sent or received, so
 * nothing edits a body after the fact — the only column that moves is `status`,
 * and only forwards, because Meta's delivery receipts arrive separately and
 * overtake each other.
 *
 * The body is **data**. It is somebody else's text, stored as it arrived and
 * rendered escaped; nothing evaluates it, and nothing in it names a column, a
 * template or a record.
 *
 * @property int $id
 * @property int $social_conversation_id
 * @property string $channel
 * @property string|null $external_message_id
 * @property string $direction
 * @property string $type
 * @property string|null $body
 * @property array<string, mixed>|null $media
 * @property string|null $template_name
 * @property string $status
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $read_at
 * @property int|null $sender_user_id
 * @property string|null $error
 */
class SocialMessage extends Model
{
    /** @use HasFactory<SocialMessageFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'social_conversation_id',
        'channel',
        'external_message_id',
        'direction',
        'type',
        'body',
        'media',
        'template_name',
        'sender_user_id',
        'sent_at',
    ];

    /**
     * `status`, `type` and `direction` share their names with methods, so every
     * instance needs the keys present — see
     * .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'direction' => MessageDirection::Inbound->value,
        'type' => MessageType::Text->value,
        'status' => MessageStatus::Received->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'media' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SocialConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SocialConversation::class, 'social_conversation_id');
    }

    /**
     * Who here sent it. Null for anything the customer wrote.
     *
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function direction(): MessageDirection
    {
        return MessageDirection::tryFrom((string) $this->getAttributeValue('direction')) ?? MessageDirection::Inbound;
    }

    public function type(): MessageType
    {
        return MessageType::tryFrom((string) $this->getAttributeValue('type')) ?? MessageType::Text;
    }

    public function status(): MessageStatus
    {
        return MessageStatus::tryFrom((string) $this->getAttributeValue('status')) ?? MessageStatus::Received;
    }

    public function isInbound(): bool
    {
        return $this->direction()->isInbound();
    }

    /**
     * What this message reads as in a list — a timeline entry, a conversation
     * preview — where there is one line to say it in.
     */
    public function preview(int $length = 120): string
    {
        $body = trim((string) $this->body);

        if ($body !== '') {
            return mb_strlen($body) > $length ? mb_substr($body, 0, $length).'…' : $body;
        }

        // An attachment with no caption still has to read as something.
        return $this->type()->label();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInbound(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('direction'), MessageDirection::Inbound->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOutbound(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('direction'), MessageDirection::Outbound->value);
    }
}
