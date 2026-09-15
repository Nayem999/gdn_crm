<?php

namespace Database\Factories;

use App\Domain\Social\Enums\TemplateStatus;
use App\Domain\Social\Models\WhatsAppTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppTemplate>
 */
class WhatsAppTemplateFactory extends Factory
{
    protected $model = WhatsAppTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'waba_id' => (string) fake()->randomNumber(9, true),
            'name' => 'order_update_'.fake()->unique()->lexify('????'),
            'language' => 'en_GB',
            'category' => 'UTILITY',
            // Approved by default: the awkward states are the ones a test asks
            // for by name, and a fixture that started pending would make every
            // ordinary send test set it up first.
            'status' => TemplateStatus::Approved->value,
            'body' => 'Hello {{1}}, your order {{2}} is on its way.',
            'variables' => ['1', '2'],
            'synced_at' => now(),
        ];
    }

    public function withStatus(TemplateStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    /**
     * Rejected, with the reason Meta gave — which is the only thing that tells
     * somebody what to change.
     */
    public function rejected(string $reason = 'The template contains promotional content in a utility category.'): static
    {
        return $this->state(fn () => [
            'status' => TemplateStatus::Rejected->value,
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * No placeholders at all, which is the common shape for a simple
     * "we are open again" message.
     */
    public function withoutVariables(): static
    {
        return $this->state(fn () => [
            'body' => 'We are open again and answering messages.',
            'variables' => [],
        ]);
    }
}
