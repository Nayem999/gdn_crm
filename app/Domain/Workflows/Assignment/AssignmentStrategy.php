<?php

namespace App\Domain\Workflows\Assignment;

/**
 * How a workflow decides who gets a record.
 *
 * A small, fixed vocabulary. The strategy is read back from a stored config to
 * decide which code runs, so a config that could name one freely would be a
 * stored row choosing what to execute — the same rule every other registry here
 * follows.
 *
 * `Fixed` is the plain case that existed before 5.7 and still covers most
 * workflows: one named person. The other three are the distributing ones.
 */
enum AssignmentStrategy: string
{
    case Fixed = 'fixed';
    case RecordOwner = 'record_owner';
    case RoundRobin = 'round_robin';
    case LoadBased = 'load_based';
    case Territory = 'territory';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'A specific person',
            self::RecordOwner => 'Whoever already owns the record',
            self::RoundRobin => 'Round robin, in turn',
            self::LoadBased => 'Whoever has the least on',
            self::Territory => 'By territory',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Fixed => 'Always the same person.',
            self::RecordOwner => 'Leaves it where it is. Useful for a step that only needs an owner to exist.',
            self::RoundRobin => 'Takes turns through a pool, evenly, in order.',
            self::LoadBased => 'Counts what each person in the pool is already carrying and gives it to the lightest.',
            self::Territory => 'Matches a field on the record — country, city, state — against a list of owners.',
        };
    }

    /**
     * Whether this strategy chooses between several people.
     */
    public function needsPool(): bool
    {
        return $this === self::RoundRobin || $this === self::LoadBased;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
