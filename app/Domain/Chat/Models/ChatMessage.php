<?php

namespace App\Domain\Chat\Models;

use App\Domain\Chat\Enums\ChatAuthor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a conversation.
 *
 * @property int $id
 * @property int $chat_conversation_id
 * @property string $body
 * @property Carbon $sent_at
 */
class ChatMessage extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'chat_conversation_id',
        'author',
        'body',
        'sent_at',
    ];

    /**
     * `author()` shares a name with the column, so it needs a default — see
     * .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'author' => ChatAuthor::Visitor->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function author(): ChatAuthor
    {
        return ChatAuthor::tryFrom((string) $this->getAttributeValue('author')) ?? ChatAuthor::Visitor;
    }

    /**
     * @return BelongsTo<ChatConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }
}
