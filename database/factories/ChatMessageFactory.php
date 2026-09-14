<?php

namespace Database\Factories;

use App\Domain\Chat\Enums\ChatAuthor;
use App\Domain\Chat\Models\ChatConversation;
use App\Domain\Chat\Models\ChatMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    protected $model = ChatMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chat_conversation_id' => ChatConversation::factory(),
            'author' => ChatAuthor::Visitor->value,
            'body' => fake()->sentence(),
            'sent_at' => now(),
        ];
    }

    public function from(ChatAuthor $author): static
    {
        return $this->state(fn () => ['author' => $author->value]);
    }

    public function in(ChatConversation $conversation): static
    {
        return $this->state(fn () => ['chat_conversation_id' => $conversation->id]);
    }
}
