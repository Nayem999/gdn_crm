<?php

namespace App\Domain\Deals\DTOs;

use App\Domain\Deals\Enums\StageOutcome;

/**
 * One stage as the form submitted it, already validated.
 *
 * `key` is null for a stage being added and carries the existing key for one
 * being kept. It is never taken from the browser as a *new* value — the action
 * derives it from the name — so a submitted key can only ever match a stage
 * that already exists on the pipeline.
 */
readonly class StageData
{
    public function __construct(
        public string $name,
        public StageOutcome $outcome = StageOutcome::Open,
        public int $probability = 0,
        public string $color = 'slate',
        public ?string $key = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        $outcome = StageOutcome::tryFrom((string) ($attributes['outcome'] ?? 'open')) ?? StageOutcome::Open;
        $key = $attributes['key'] ?? null;

        return new self(
            name: trim((string) ($attributes['name'] ?? '')),
            outcome: $outcome,
            // A closed stage's probability is not the administrator's to set:
            // a won deal is certain and a lost one is not happening, and a
            // typed 60% against "Closed won" would quietly skew every forecast.
            probability: $outcome->fixedProbability() ?? (int) ($attributes['probability'] ?? 0),
            color: (string) ($attributes['color'] ?? 'slate'),
            key: $key === null || $key === '' ? null : (string) $key,
        );
    }
}
