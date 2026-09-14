<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Notifications\NotificationEventRegistry;
use App\Models\User;
use Database\Factories\NotificationLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt to deliver one notification to one recipient on one channel.
 *
 * The body is deliberately not stored — a rendered message can carry personal
 * data, and the log's job is to say what happened, not to keep a copy.
 *
 * @property int $id
 * @property string $event
 * @property string $channel
 * @property string $recipient_type
 * @property int|null $user_id
 * @property string|null $recipient
 * @property string $status
 * @property string|null $subject
 * @property string|null $error
 * @property int $attempts
 * @property Carbon|null $sent_at
 * @property Carbon $created_at
 */
class NotificationLog extends Model
{
    /** @use HasFactory<NotificationLogFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event',
        'channel',
        'recipient_type',
        'user_id',
        'recipient',
        'status',
        'subject',
        'error',
        'attempts',
        'sent_at',
    ];

    /**
     * Columns that share a name with a method on this model.
     *
     * Without a default the key can be missing on a freshly created instance,
     * and Laravel then takes the property read for a relation and calls the
     * method. See .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'queued',
        'channel' => 'in_app',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function status(): NotificationStatus
    {
        return NotificationStatus::tryFrom((string) $this->getAttributeValue('status')) ?? NotificationStatus::Queued;
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::tryFrom((string) $this->getAttributeValue('channel')) ?? NotificationChannel::InApp;
    }

    public function recipientType(): RecipientType
    {
        return RecipientType::tryFrom($this->recipient_type) ?? RecipientType::Admin;
    }

    public function eventLabel(): string
    {
        $event = NotificationEventRegistry::find($this->event);

        return $event === null ? $this->event : $event->label;
    }

    public function markSent(): void
    {
        $this->forceFill([
            'status' => NotificationStatus::Sent->value,
            'sent_at' => now(),
            'error' => null,
        ])->save();
    }

    /**
     * Record a failure. The reason is stored so the log viewer can show why, but
     * it is truncated rather than kept whole — a provider can echo the payload
     * back in an error string.
     */
    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'status' => NotificationStatus::Failed->value,
            'error' => mb_substr($reason, 0, 500),
        ])->save();
    }

    public function markSkipped(string $reason): void
    {
        $this->forceFill([
            'status' => NotificationStatus::Skipped->value,
            'error' => mb_substr($reason, 0, 500),
        ])->save();
    }

    /**
     * @param  Builder<NotificationLog>  $query
     * @return Builder<NotificationLog>
     */
    public function scopeRetryable(Builder $query): Builder
    {
        return $query->where('status', NotificationStatus::Failed->value);
    }
}
