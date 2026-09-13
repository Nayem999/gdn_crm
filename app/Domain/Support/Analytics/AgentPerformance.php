<?php

namespace App\Domain\Support\Analytics;

/**
 * One row of the agent table.
 *
 * `breached` sits beside `resolved` rather than being turned into a score: how
 * many a person got through and how many of those were late are two facts a
 * manager needs separately, and a single "performance" number would hide which
 * of them changed.
 */
readonly class AgentPerformance
{
    public function __construct(
        public int $agentId,
        public string $name,
        public int $resolved,
        public int $stillOpen,
        public ?float $averageResolutionHours,
        public int $breached,
    ) {}

    /**
     * The share of this agent's resolved tickets that were late, or null when
     * they resolved none — nought per cent of nothing is not a good record.
     */
    public function breachRate(): ?float
    {
        return $this->resolved === 0 ? null : round($this->breached / $this->resolved * 100, 1);
    }
}
