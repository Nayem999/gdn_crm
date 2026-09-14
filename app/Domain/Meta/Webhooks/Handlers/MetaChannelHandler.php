<?php

namespace App\Domain\Meta\Webhooks\Handlers;

use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\Models\IntegrationEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * What one Meta channel does with a delivery.
 *
 * Returning a status rather than throwing on the ordinary refusals: a lead-ads
 * event for a form nobody has mapped, or a message from a page this
 * installation does not manage, is a delivery to record and move past, not a
 * failure to alert on. Genuine faults — Meta unreachable, a page token that has
 * died — throw, because those are worth retrying and worth seeing in red.
 */
interface MetaChannelHandler
{
    /**
     * @param  array<string, mixed>  $payload  The decoded body, as data.
     * @return array{status: IntegrationEventStatus, outcome: string, record?: Model|null}
     */
    public function handle(IntegrationEvent $event, array $payload): array;
}
