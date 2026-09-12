<?php

namespace App\Domain\Mail\Models;

use Database\Factories\InboundMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A message that arrived in the monitored mailbox.
 *
 * @property int $id
 * @property string $message_id
 * @property string|null $in_reply_to
 * @property string|null $references
 * @property string $from_email
 * @property string|null $from_name
 * @property string|null $to_email
 * @property string|null $subject
 * @property string|null $body
 * @property string $folder
 * @property int $uid
 * @property int $uid_validity
 * @property string|null $related_type
 * @property int|null $related_id
 * @property int|null $email_message_id
 * @property Carbon $received_at
 */
class InboundMessage extends Model
{
    /** @use HasFactory<InboundMessageFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'message_id',
        'in_reply_to',
        'references',
        'from_email',
        'from_name',
        'to_email',
        'subject',
        'body',
        'folder',
        'uid',
        'uid_validity',
        'related_type',
        'related_id',
        'email_message_id',
        'received_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'uid' => 'integer',
            'uid_validity' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<EmailMessage, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }
}
