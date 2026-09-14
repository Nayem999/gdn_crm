<?php

namespace App\Domain\Meta\Webhooks;

use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Domain\Ingestion\PayloadReader;
use App\Domain\Meta\Enums\MetaChannel;
use App\Domain\Meta\Webhooks\Handlers\MetaChannelHandler;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * What happens to a Meta delivery once it is safely written down.
 *
 * Meta's deliveries do not go through the generic mapper, and that is not an
 * oversight: a lead-ads webhook carries an id and nothing else — no name, no
 * email, no campaign — so there is nothing to map until the lead has been
 * fetched from Graph with a page token. A message carries a sender and a body,
 * but becoming a conversation is not the same shape as becoming a record.
 *
 * So each channel has a handler, and this routes to it. The handlers arrive with
 * the tasks that need them: lead ads in 12.6, Messenger in 12.8, WhatsApp in
 * 12.10. Until one is registered its channel settles as **skipped, not failed** —
 * a delivery arriving for something this installation cannot yet do is not an
 * error anybody should be paged about, and marking it failed would fill the
 * health panel with red for a feature that has not shipped.
 */
class MetaEventProcessor
{
    /**
     * Handlers, keyed by channel. Registered in a service provider by the task
     * that owns each one.
     *
     * @var array<string, class-string<MetaChannelHandler>>
     */
    private static array $handlers = [];

    /**
     * @param  class-string<MetaChannelHandler>  $handler
     */
    public static function handle(MetaChannel $channel, string $handler): void
    {
        self::$handlers[$channel->value] = $handler;
    }

    public static function handlerFor(MetaChannel $channel): ?MetaChannelHandler
    {
        $class = self::$handlers[$channel->value] ?? null;

        return $class === null ? null : app($class);
    }

    /**
     * Forget every registration. For tests, which must not inherit a handler
     * another test bound.
     */
    public static function forgetHandlers(): void
    {
        self::$handlers = [];
    }

    /**
     * @return array{status: IntegrationEventStatus, outcome: string, record?: Model|null}
     */
    public function __invoke(IntegrationEvent $event, MetaChannel $channel): array
    {
        $payload = PayloadReader::decode((string) $event->payload);

        if ($payload === null) {
            throw new RuntimeException('The body was not a JSON object.');
        }

        // Meta never crosses its object types, so a payload whose object does
        // not match the channel it arrived on did not come from Meta — or came
        // from a subscription pointed at the wrong URL, which is worth seeing in
        // the log rather than silently processing.
        $object = $payload['object'] ?? null;

        if (is_string($object) && $object !== $channel->object()) {
            return [
                'status' => IntegrationEventStatus::Skipped,
                // `outcome` is a 16-character token vocabulary shared with
                // every other source in the delivery log; the detail is the
                // payload, which is kept.
                'outcome' => 'wrong_object',
            ];
        }

        $handler = self::handlerFor($channel);

        if ($handler === null) {
            return [
                'status' => IntegrationEventStatus::Skipped,
                'outcome' => 'no_handler',
            ];
        }

        return $handler->handle($event, $payload);
    }
}
