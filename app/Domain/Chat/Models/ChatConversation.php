<?php

namespace App\Domain\Chat\Models;

use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadCaptureForm;
use Database\Factories\ChatConversationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One visitor's conversation with the website.
 *
 * @property int $id
 * @property int $lead_capture_form_id
 * @property string $session_id
 * @property string|null $visitor_name
 * @property string|null $visitor_email
 * @property string|null $visitor_phone
 * @property string|null $page_url
 * @property int|null $lead_id
 * @property Carbon $started_at
 * @property Carbon $last_message_at
 */
class ChatConversation extends Model
{
    /** @use HasFactory<ChatConversationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'lead_capture_form_id',
        'session_id',
        'visitor_name',
        'visitor_email',
        'visitor_phone',
        'page_url',
        'lead_id',
        'started_at',
        'last_message_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ChatMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('sent_at');
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<LeadCaptureForm, $this>
     */
    public function widget(): BelongsTo
    {
        return $this->belongsTo(LeadCaptureForm::class, 'lead_capture_form_id');
    }

    /**
     * Whether we know enough about the visitor to make a lead worth creating.
     *
     * An address or a number, exactly as the capture form insists: a lead with
     * no way to reach it is a row somebody has to delete later.
     */
    public function isIdentifiable(): bool
    {
        return ($this->visitor_email !== null && $this->visitor_email !== '')
            || ($this->visitor_phone !== null && $this->visitor_phone !== '');
    }

    /**
     * The conversation as text, for the lead's description.
     */
    public function transcript(): string
    {
        return $this->messages
            ->map(fn (ChatMessage $message): string => $message->author()->label().': '.$message->body)
            ->implode("\n");
    }
}
