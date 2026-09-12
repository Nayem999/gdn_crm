<?php

namespace App\Jobs;

use App\Domain\Webhooks\Enums\WebhookDeliveryStatus;
use App\Domain\Webhooks\Models\WebhookDelivery;
use App\Domain\Webhooks\WebhookSignature;
use App\Domain\Workflows\Webhooks\WebhookTarget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Send one delivery, and keep trying for a while.
 *
 * **Retries are the queue's, not a loop of our own.** Laravel already backs off,
 * counts attempts and stops; reimplementing that here would be a second retry
 * policy to reason about, and the two would disagree the first time somebody
 * changed one.
 *
 * The backoff is minutes rather than seconds because the failures worth
 * retrying are somebody else's deploy or somebody else's outage, and neither is
 * over in ten seconds. Six attempts over roughly an hour, then it stops and says
 * so — an endpoint that has been down for an hour is a person's problem, not a
 * queue's.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300, 900, 1800];
    }

    public function __construct(public readonly int $deliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);

        if ($delivery === null || $delivery->status === WebhookDeliveryStatus::Delivered) {
            return;
        }

        $endpoint = $delivery->endpoint;

        if ($endpoint === null) {
            return;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES) ?: '{}';
        $timestamp = Carbon::now()->getTimestamp();

        $delivery->forceFill([
            'attempts' => $delivery->attempts + 1,
            'last_attempt_at' => Carbon::now(),
        ])->save();

        // The same guard the workflow webhook action uses: an endpoint an
        // administrator typed is still somebody typing a URL, and "http://
        // 169.254.169.254" is a cloud metadata service rather than a customer's
        // server.
        $refusal = WebhookTarget::refuse($endpoint->url);

        if ($refusal !== null) {
            $this->giveUp($delivery, $refusal);

            return;
        }

        try {
            $response = Http::timeout(15)
                ->connectTimeout(10)
                ->withHeaders([
                    WebhookSignature::HEADER => WebhookSignature::for($body, $endpoint->secret, $timestamp),
                    'Content-Type' => 'application/json',
                    'User-Agent' => config('app.name').' webhooks',
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);
        } catch (Throwable $failure) {
            $this->record($delivery, null, $failure->getMessage());

            throw $failure;
        }

        if ($response->successful()) {
            $delivery->forceFill([
                'status' => WebhookDeliveryStatus::Delivered,
                'response_status' => $response->status(),
                'error' => null,
                'delivered_at' => Carbon::now(),
            ])->save();

            return;
        }

        $this->record($delivery, $response->status(), mb_substr(trim($response->body()), 0, 500));

        // Thrown so the queue retries it. A return would mark the job done and
        // the delivery would sit at "trying" for ever.
        throw new \RuntimeException('Endpoint answered '.$response->status().'.');
    }

    /**
     * The last attempt has failed; stop and say so.
     */
    public function failed(Throwable $exception): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if ($delivery !== null && $delivery->status !== WebhookDeliveryStatus::Delivered) {
            $delivery->forceFill(['status' => WebhookDeliveryStatus::Failed])->save();
        }
    }

    private function record(WebhookDelivery $delivery, ?int $status, string $error): void
    {
        $delivery->forceFill([
            'response_status' => $status,
            'error' => $error,
        ])->save();
    }

    private function giveUp(WebhookDelivery $delivery, string $reason): void
    {
        $delivery->forceFill([
            'status' => WebhookDeliveryStatus::Failed,
            'error' => $reason,
        ])->save();
    }
}
