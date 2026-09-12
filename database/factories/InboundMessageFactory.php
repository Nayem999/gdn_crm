<?php

namespace Database\Factories;

use App\Domain\Mail\Models\InboundMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InboundMessage>
 */
class InboundMessageFactory extends Factory
{
    protected $model = InboundMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => (string) Str::uuid().'@example.com',
            'from_email' => fake()->unique()->safeEmail(),
            'from_name' => fake()->name(),
            'to_email' => 'crm@example.com',
            'subject' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'folder' => 'INBOX',
            'uid' => fake()->unique()->numberBetween(1, 100000),
            'uid_validity' => 1,
            'received_at' => now(),
        ];
    }
}
