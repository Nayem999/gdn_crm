<?php

namespace Database\Factories;

use App\Domain\Social\Enums\MessageDirection;
use App\Domain\Social\Enums\MessageStatus;
use App\Domain\Social\Enums\MessageType;
use App\Domain\Social\Models\SocialConversation;
use App\Domain\Social\Models\SocialMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialMessage>
 */
class SocialMessageFactory extends Factory
{
    protected $model = SocialMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'social_conversation_id' => SocialConversation::factory(),
            'channel' => 'messenger',
            'external_message_id' => 'mid.'.fake()->unique()->lexify('????????????????'),
            'direction' => MessageDirection::Inbound->value,
            'type' => MessageType::Text->value,
            'body' => fake()->sentence(),
            'status' => MessageStatus::Received->value,
        ];
    }

    public function inConversation(SocialConversation $conversation): static
    {
        return $this->state(fn () => [
            'social_conversation_id' => $conversation->id,
            // Copied from the conversation, the way the real thing does: the
            // unique index on (channel, external_message_id) has to stand
            // without a join.
            'channel' => $conversation->channel,
        ]);
    }

    public function outbound(?User $sender = null): static
    {
        return $this->state(fn () => [
            'direction' => MessageDirection::Outbound->value,
            'status' => MessageStatus::Sent->value,
            'sender_user_id' => $sender?->id,
            'sent_at' => now(),
        ]);
    }

    public function template(string $name): static
    {
        return $this->state(fn () => [
            'direction' => MessageDirection::Outbound->value,
            'type' => MessageType::Template->value,
            'template_name' => $name,
            'status' => MessageStatus::Sent->value,
            'sent_at' => now(),
        ]);
    }

    /**
     * An attachment, which is a message with no body worth showing.
     */
    public function attachment(MessageType $type = MessageType::Image): static
    {
        return $this->state(fn () => [
            'type' => $type->value,
            'body' => null,
            'media' => [['type' => $type->value, 'url' => 'https://example.com/asset.jpg']],
        ]);
    }

    public function failed(string $error = 'Meta refused the message.'): static
    {
        return $this->state(fn () => [
            'direction' => MessageDirection::Outbound->value,
            'status' => MessageStatus::Failed->value,
            'error' => $error,
        ]);
    }
}
