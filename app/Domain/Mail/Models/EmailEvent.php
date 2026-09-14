<?php

namespace App\Domain\Mail\Models;

use App\Domain\Mail\Enums\EmailEventType;
use Database\Factories\EmailEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing a provider reported about one message.
 *
 * @property int $id
 * @property int $email_message_id
 * @property EmailEventType $type
 * @property Carbon $occurred_at
 * @property string|null $url
 * @property string|null $reason
 * @property array<string, mixed>|null $payload
 * @property string $signature
 */
class EmailEvent extends Model
{
    /** @use HasFactory<EmailEventFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'email_message_id',
        'type',
        'occurred_at',
        'url',
        'reason',
        'payload',
        'signature',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EmailEventType::class,
            'occurred_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<EmailMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }
}
