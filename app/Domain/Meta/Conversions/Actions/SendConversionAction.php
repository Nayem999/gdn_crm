<?php

namespace App\Domain\Meta\Conversions\Actions;

use App\Domain\Meta\Conversions\Enums\ConversionStatus;
use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Graph\MetaGraphClient;
use App\Domain\Meta\Models\MetaAccount;
use App\Domain\Meta\Models\MetaConversionEvent;
use Illuminate\Support\Carbon;

/**
 * The call itself, and an honest record of what came back.
 *
 * **Meta answers a rejected event with 200.** The body carries
 * `events_received` and a `messages` array, and an event it could not match or
 * would not accept is reported there rather than as an HTTP failure. So the
 * response is read rather than trusted, and stored either way — "sent" here
 * means Meta said it received it, which is the strongest claim available.
 *
 * A send that has already succeeded returns without calling: the job may be
 * retried by the queue after a timeout that happened *after* Meta accepted the
 * event, and repeating it would double-count the conversion the unique index
 * was there to prevent.
 */
class SendConversionAction
{
    public function __construct(private readonly MetaGraphClient $client) {}

    public function __invoke(MetaConversionEvent $event): bool
    {
        if ($event->status() === ConversionStatus::Sent) {
            return true;
        }

        if ($event->payload === null) {
            return false;
        }

        if ($event->isTooOldToSend()) {
            $this->fail($event, 'Meta will not accept an event more than seven days after it happened.');

            return false;
        }

        $token = $this->token();

        if ($token === null) {
            $this->fail($event, 'No Meta connection has a token. Connect Meta under Settings → Meta.');

            return false;
        }

        $event->forceFill(['attempts' => $event->attempts + 1])->save();

        try {
            $response = $this->client->post($event->dataset_id.'/events', [
                // Meta wants the events array as a JSON *string* in a form
                // field, not as nested JSON. Sending it nested is accepted and
                // silently ignored, which reports every event as received and
                // matches none of them.
                'data' => json_encode([$event->payload], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ], $token);
        } catch (MetaApiException $exception) {
            $this->fail($event, $exception->userMessage());

            return false;
        }

        $received = (int) ($response['events_received'] ?? 0);

        if ($received < 1) {
            $this->fail($event, $this->messagesFrom($response) ?? 'Meta accepted the request but recorded no event.');

            return false;
        }

        $event->forceFill([
            'status' => ConversionStatus::Sent->value,
            'response_status' => 200,
            'response' => $this->messagesFrom($response),
            'error' => null,
            'sent_at' => Carbon::now(),
        ])->save();

        return true;
    }

    /**
     * The token to call with.
     *
     * The connection's own, because the dataset belongs to the same business
     * and a token that can read the ad account can write to its dataset. An
     * installation with two connections uses the most recent, which is the one
     * the settings screen shows as connected.
     */
    private function token(): ?string
    {
        return MetaAccount::query()->latest('id')->first()?->token()?->value;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function messagesFrom(array $response): ?string
    {
        $messages = $response['messages'] ?? null;

        if (! is_array($messages) || $messages === []) {
            return null;
        }

        return mb_substr((string) json_encode($messages), 0, 500);
    }

    private function fail(MetaConversionEvent $event, string $error): void
    {
        $event->forceFill([
            'status' => ConversionStatus::Failed->value,
            'error' => mb_substr($error, 0, 500),
        ])->save();
    }
}
