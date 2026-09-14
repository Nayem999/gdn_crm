<?php

namespace Database\Factories;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationTemplate>
 */
class NotificationTemplateFactory extends Factory
{
    protected $model = NotificationTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event' => 'user.joined',
            'channel' => NotificationChannel::InApp->value,
            'subject' => 'Someone joined',
            'body' => '{{user.name}} accepted their invitation.',
        ];
    }

    public function forEvent(string $event, NotificationChannel $channel): static
    {
        return $this->state(fn () => ['event' => $event, 'channel' => $channel->value]);
    }

    public function saying(string $body, ?string $subject = null): static
    {
        return $this->state(fn () => ['body' => $body, 'subject' => $subject]);
    }
}
