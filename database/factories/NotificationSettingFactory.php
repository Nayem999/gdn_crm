<?php

namespace Database\Factories;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Notifications\Models\NotificationSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationSetting>
 */
class NotificationSettingFactory extends Factory
{
    protected $model = NotificationSetting::class;

    /**
     * Only cells that **differ** from the registry default are stored, so a row
     * here is by definition an override.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event' => 'user.joined',
            'recipient_type' => RecipientType::Admin->value,
            'channel' => NotificationChannel::Email->value,
            'enabled' => true,
        ];
    }

    public function off(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
