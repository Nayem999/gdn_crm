<?php

namespace Database\Factories;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
{
    protected $model = NotificationPreference::class;

    /**
     * A preference row exists to record a **mute**: turning something back on
     * deletes the row rather than storing a positive override. The default
     * here is false for that reason.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event' => null,
            'channel' => NotificationChannel::InApp->value,
            'enabled' => false,
        ];
    }

    public function muting(string $event): static
    {
        return $this->state(fn () => ['event' => $event]);
    }

    public function on(NotificationChannel $channel): static
    {
        return $this->state(fn () => ['channel' => $channel->value]);
    }
}
