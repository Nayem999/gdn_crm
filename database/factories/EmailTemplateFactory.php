<?php

namespace Database\Factories;

use App\Domain\Mail\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailTemplate>
 */
class EmailTemplateFactory extends Factory
{
    protected $model = EmailTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'module' => 'contact',
            'subject' => 'Hello {{contact.first_name}}',
            'body' => '<p>Hello {{contact.first_name}}, from {{company.name}}.</p>',
            'is_active' => true,
            'track_opens' => false,
            'track_clicks' => false,
        ];
    }

    public function tracked(): static
    {
        return $this->state(fn (): array => ['track_opens' => true, 'track_clicks' => true]);
    }

    public function forModule(string $module): static
    {
        return $this->state(fn (): array => ['module' => $module]);
    }
}
