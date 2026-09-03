<?php

namespace App\Domain\Leads\DTOs;

/**
 * Whether a lead meets the requirements for being qualified, and which ones it
 * does not.
 *
 * With no requirements configured every lead passes. That is the intended
 * default: qualification gating is something an administrator opts into, not
 * something that silently blocks a fresh installation.
 */
readonly class QualificationCheck
{
    /**
     * @param  array<int, array{label: string, met: bool}>  $requirements
     */
    public function __construct(public array $requirements = []) {}

    public function passes(): bool
    {
        return $this->unmet() === [];
    }

    /**
     * @return array<int, string>
     */
    public function unmet(): array
    {
        return array_values(array_map(
            fn (array $requirement) => $requirement['label'],
            array_filter($this->requirements, fn (array $requirement) => ! $requirement['met'])
        ));
    }

    /**
     * @return array<int, string>
     */
    public function met(): array
    {
        return array_values(array_map(
            fn (array $requirement) => $requirement['label'],
            array_filter($this->requirements, fn (array $requirement) => $requirement['met'])
        ));
    }

    /**
     * A sentence naming what is still missing, for the message shown when a
     * move to Qualified is refused.
     */
    public function reason(): ?string
    {
        $unmet = $this->unmet();

        if ($unmet === []) {
            return null;
        }

        return 'This lead is not ready to qualify: '.implode('; ', $unmet).'.';
    }
}
