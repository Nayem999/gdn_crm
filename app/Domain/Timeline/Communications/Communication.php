<?php

namespace App\Domain\Timeline\Communications;

use Carbon\CarbonInterface;

/**
 * One message to or from the customer, whichever channel carried it.
 *
 * Four tables with four shapes produce these, and the timeline renders this
 * one shape — so ordering, paging and the view are written once rather than
 * four times.
 */
final readonly class Communication
{
    public function __construct(
        public CommunicationChannel $channel,
        /** True when we sent it, false when they did. */
        public bool $outbound,
        public CarbonInterface $occurredAt,
        public string $title,
        public ?string $body = null,
        /** Delivery state, where the channel reports one. */
        public ?string $status = null,
        public ?string $counterparty = null,
        public string $sourceKey = '',
    ) {}

    public function directionLabel(): string
    {
        return $this->outbound ? 'Sent' : 'Received';
    }
}
