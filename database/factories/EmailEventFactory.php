<?php

namespace Database\Factories;

use App\Domain\Mail\Enums\EmailEventType;
use App\Domain\Mail\Models\EmailEvent;
use App\Domain\Mail\Models\EmailMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailEvent>
 */
class EmailEventFactory extends Factory
{
    protected $model = EmailEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email_message_id' => EmailMessage::factory(),
            'type' => EmailEventType::Delivered->value,
            'occurred_at' => now(),
            'url' => null,
            'reason' => null,
            'payload' => [],
            // NOT NULL on the table: a provider event that carried no
            // signature is one we could not have verified, and the column
            // records what arrived rather than allowing a gap.
            'signature' => fake()->sha256(),
        ];
    }

    public function of(EmailEventType $type): static
    {
        return $this->state(fn () => ['type' => $type->value]);
    }

    public function belongingTo(EmailMessage $message): static
    {
        return $this->state(fn () => ['email_message_id' => $message->id]);
    }
}
