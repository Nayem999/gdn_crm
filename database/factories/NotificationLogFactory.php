<?php

namespace Database\Factories;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Notifications\Models\NotificationLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationLog>
 */
class NotificationLogFactory extends Factory
{
    protected $model = NotificationLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event' => 'user.joined',
            'channel' => NotificationChannel::InApp->value,
            'recipient_type' => RecipientType::Admin->value,
            'user_id' => User::factory(),
            'recipient' => fake()->safeEmail(),
            'status' => NotificationStatus::Queued->value,
            'subject' => null,
            'error' => null,
            'attempts' => 0,
            'sent_at' => null,
        ];
    }

    /**
     * Sent, with the stamp that goes with it — a row saying "sent" with no
     * sent_at describes something the engine cannot produce.
     */
    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => NotificationStatus::Sent->value,
            'sent_at' => now(),
            'attempts' => 1,
        ]);
    }

    public function failed(string $error = 'The provider refused it.'): static
    {
        return $this->state(fn () => [
            'status' => NotificationStatus::Failed->value,
            'error' => $error,
            'attempts' => 1,
        ]);
    }

    public function on(NotificationChannel $channel): static
    {
        return $this->state(fn () => ['channel' => $channel->value]);
    }
}
