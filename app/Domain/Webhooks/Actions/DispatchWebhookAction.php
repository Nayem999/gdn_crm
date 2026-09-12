<?php

namespace App\Domain\Webhooks\Actions;

use App\Domain\Api\ApiModules;
use App\Domain\Webhooks\Enums\WebhookDeliveryStatus;
use App\Domain\Webhooks\Models\WebhookDelivery;
use App\Domain\Webhooks\Models\WebhookEndpoint;
use App\Domain\Webhooks\WebhookEvents;
use App\Jobs\DeliverWebhook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Something happened; tell whoever asked to be told.
 *
 * The payload is built **now**, from the record as it is at this moment, and
 * stored on the delivery. A job that looked the record up when it finally ran
 * would report the state at delivery time — which after a retry an hour later
 * is a different fact, and after a deletion is no fact at all.
 */
class DispatchWebhookAction
{
    /**
     * @return array<int, WebhookDelivery>
     */
    public function handle(string $module, string $action, Model $record): array
    {
        $event = WebhookEvents::keyFor($module, $action);

        if (! WebhookEvents::exists($event)) {
            return [];
        }

        $endpoints = WebhookEndpoint::query()->where('is_active', true)->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => $endpoint->wants($event));

        if ($endpoints->isEmpty()) {
            return [];
        }

        $payload = $this->payload($module, $event, $record);

        $deliveries = [];

        foreach ($endpoints as $endpoint) {
            $delivery = WebhookDelivery::query()->create([
                'webhook_endpoint_id' => $endpoint->id,
                'event' => $event,
                'payload' => $payload,
                'status' => WebhookDeliveryStatus::Pending,
                'attempts' => 0,
            ]);

            DeliverWebhook::dispatch($delivery->id);

            $deliveries[] = $delivery;
        }

        return $deliveries;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $module, string $event, Model $record): array
    {
        $api = ApiModules::find($module);

        return [
            // The receiver's handle on this exact delivery, so a retry can be
            // recognised as the same event rather than a second one.
            'id' => (string) Str::uuid(),
            'event' => $event,
            'occurred_at' => Carbon::now()->toIso8601String(),
            'data' => $api === null ? ['id' => $record->getKey()] : $api->toArray($record),
        ];
    }
}
