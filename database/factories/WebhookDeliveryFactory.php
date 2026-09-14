<?php

namespace Database\Factories;

use App\Domain\Webhooks\Models\WebhookDelivery;
use App\Domain\Webhooks\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'webhook_endpoint_id' => WebhookEndpoint::factory(),
            'event' => 'lead.created',
            'payload' => ['id' => 1],
            'status' => 'pending',
            'attempts' => 0,
            'response_status' => null,
            'error' => null,
            'delivered_at' => null,
            'last_attempt_at' => null,
        ];
    }

    public function delivered(int $responseStatus = 200): static
    {
        return $this->state(fn () => [
            'status' => 'delivered',
            'response_status' => $responseStatus,
            'attempts' => 1,
            'delivered_at' => now(),
            'last_attempt_at' => now(),
        ]);
    }

    public function failed(string $error = 'Connection refused.'): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'error' => $error,
            'attempts' => 1,
            'last_attempt_at' => now(),
        ]);
    }

    public function to(WebhookEndpoint $endpoint): static
    {
        return $this->state(fn () => ['webhook_endpoint_id' => $endpoint->id]);
    }
}
