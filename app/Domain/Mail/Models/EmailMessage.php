<?php

namespace App\Domain\Mail\Models;

use App\Domain\Mail\Enums\EmailStatus;
use App\Domain\Notifications\Models\NotificationLog;
use Database\Factories\EmailMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One message we handed to a provider, and where it got to.
 *
 * The body is not stored, for the same reason the notification log does not
 * store one: a rendered message carries personal data, and the log's job is to
 * say what happened rather than to keep a copy of what was said.
 *
 * @property int $id
 * @property string $provider
 * @property string|null $message_id
 * @property string|null $tracking_id
 * @property string $to_email
 * @property string|null $to_name
 * @property string|null $subject
 * @property EmailStatus $status
 * @property int $open_count
 * @property int $click_count
 * @property Carbon $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $opened_at
 * @property Carbon|null $clicked_at
 * @property Carbon|null $failed_at
 * @property string|null $reason
 * @property int|null $notification_log_id
 * @property string|null $related_type
 * @property int|null $related_id
 */
class EmailMessage extends Model
{
    /** @use HasFactory<EmailMessageFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'message_id',
        'tracking_id',
        'to_email',
        'to_name',
        'subject',
        'status',
        'open_count',
        'click_count',
        'sent_at',
        'delivered_at',
        'opened_at',
        'clicked_at',
        'failed_at',
        'reason',
        'notification_log_id',
        'related_type',
        'related_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EmailStatus::class,
            'open_count' => 'integer',
            'click_count' => 'integer',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<EmailEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(EmailEvent::class)->orderByDesc('occurred_at');
    }

    /**
     * @return BelongsTo<NotificationLog, $this>
     */
    public function notificationLog(): BelongsTo
    {
        return $this->belongsTo(NotificationLog::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The message a provider is talking about.
     *
     * Scoped by provider as well as id: two providers' id spaces have nothing
     * to do with each other, and a webhook from one must never be able to name
     * a message sent by the other.
     *
     * @param  Builder<EmailMessage>  $query
     * @return Builder<EmailMessage>
     */
    public function scopeFromProvider(Builder $query, string $provider, string $messageId, ?string $recipient = null): Builder
    {
        return $query
            ->where('provider', $provider)
            ->where('message_id', $messageId)
            ->when($recipient !== null, fn (Builder $rows) => $rows->where('to_email', $recipient));
    }
}
