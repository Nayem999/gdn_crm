<?php

namespace Database\Factories;

use App\Domain\Mail\Enums\EmailStatus;
use App\Domain\Mail\Models\EmailMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmailMessage>
 */
class EmailMessageFactory extends Factory
{
    protected $model = EmailMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'postmark',
            'message_id' => (string) Str::uuid(),
            'to_email' => fake()->unique()->safeEmail(),
            'to_name' => fake()->name(),
            'subject' => fake()->sentence(4),
            'status' => EmailStatus::Sent,
            'open_count' => 0,
            'click_count' => 0,
            'sent_at' => now(),
        ];
    }

    public function from(string $provider, string $messageId): static
    {
        return $this->state(fn (): array => ['provider' => $provider, 'message_id' => $messageId]);
    }

    public function to(string $email): static
    {
        return $this->state(fn (): array => ['to_email' => $email]);
    }
}
