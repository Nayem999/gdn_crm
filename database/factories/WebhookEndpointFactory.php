<?php

namespace Database\Factories;

use App\Domain\Webhooks\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' integration',
            'url' => 'https://example.com/hook',
            'secret' => Str::random(40),
            'events' => ['contacts.created'],
            'is_active' => true,
        ];
    }

    /**
     * @param  array<int, string>  $events
     */
    public function listeningTo(array $events): static
    {
        return $this->state(fn (): array => ['events' => $events]);
    }
}
