<?php

namespace App\Domain\Workflows\DTOs;

use App\Domain\Shared\Filters\FilterGroup;
use App\Domain\Workflows\Enums\WorkflowTrigger;
use App\Domain\Workflows\WorkflowModules;
use Cron\CronExpression;

/**
 * A workflow definition as a form submitted it, already normalised.
 *
 * Everything a payload could use to name something it should not is resolved
 * here against a registry: the module against `WorkflowModules`, the trigger
 * against its enum, the watched field against that module's own field set. What
 * comes out the other side cannot name a class, a table or a column the module
 * does not offer.
 */
readonly class WorkflowData
{
    /**
     * @param  array<string, mixed>  $conditions  The filter builder's array shape.
     * @param  array<int, WorkflowActionData>  $actions
     */
    public function __construct(
        public string $name,
        public string $module,
        public WorkflowTrigger $trigger,
        public ?string $description = null,
        public ?string $triggerField = null,
        public ?int $dateOffsetMinutes = null,
        public ?string $scheduleExpression = null,
        public array $conditions = FilterGroup::EMPTY,
        public bool $isActive = false,
        public bool $runOncePerRecord = false,
        public array $actions = [],
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        $module = (string) ($attributes['module'] ?? '');
        // A module the registry does not list becomes the empty string rather
        // than a class; the action then refuses it outright.
        $module = WorkflowModules::has($module) ? $module : '';

        $trigger = WorkflowTrigger::tryFrom((string) ($attributes['trigger_event'] ?? ''))
            ?? WorkflowTrigger::RecordCreated;

        return new self(
            name: trim((string) ($attributes['name'] ?? '')),
            module: $module,
            trigger: $trigger,
            description: self::text($attributes, 'description'),
            triggerField: self::field($module, $trigger, $attributes['trigger_field'] ?? null),
            // Only a date trigger carries one, so changing the trigger cannot
            // leave an offset behind on a workflow that has no date to offset.
            dateOffsetMinutes: $trigger === WorkflowTrigger::DateReached
                ? self::offset($attributes['date_offset_minutes'] ?? null)
                : null,
            // Only a scheduled trigger carries one, for the same reason the
            // offset belongs only to a date trigger.
            scheduleExpression: $trigger === WorkflowTrigger::Scheduled
                ? self::schedule($attributes['schedule_expression'] ?? null)
                : null,
            conditions: self::conditions($attributes['conditions'] ?? null),
            isActive: (bool) ($attributes['is_active'] ?? false),
            runOncePerRecord: (bool) ($attributes['run_once_per_record'] ?? false),
            actions: self::actions($attributes['actions'] ?? []),
        );
    }

    /**
     * The columns this writes on a workflow row.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'module' => $this->module,
            'trigger_event' => $this->trigger->value,
            'trigger_field' => $this->triggerField,
            'date_offset_minutes' => $this->dateOffsetMinutes,
            'schedule_expression' => $this->scheduleExpression,
            'conditions' => $this->conditions,
            'is_active' => $this->isActive,
            'run_once_per_record' => $this->runOncePerRecord,
        ];
    }

    /**
     * The watched field, but only if this module actually has it and this
     * trigger actually needs one.
     *
     * A trigger that needs no field never keeps one: otherwise switching a
     * workflow from "when a field changes" to "when a record is created" would
     * leave a field behind that nothing reads and everybody reading the row
     * would wonder about.
     */
    private static function field(string $module, WorkflowTrigger $trigger, mixed $field): ?string
    {
        if (! $trigger->needsField()) {
            return null;
        }

        $key = trim((string) $field);

        if ($key === '') {
            return null;
        }

        $allowed = $trigger->needsDateField()
            ? WorkflowModules::hasDateField($module, $key)
            : WorkflowModules::hasField($module, $key);

        return $allowed ? $key : null;
    }

    /**
     * The condition tree, put through `FilterGroup` and back.
     *
     * The round trip is the validation: `fromArray()` drops anything that is
     * not a coherent condition, so what is stored is always something the
     * evaluator can read.
     *
     * @return array<string, mixed>
     */
    private static function conditions(mixed $conditions): array
    {
        if (! is_array($conditions)) {
            return FilterGroup::EMPTY;
        }

        return self::groupToArray(FilterGroup::fromArray($conditions));
    }

    /**
     * @return array<string, mixed>
     */
    private static function groupToArray(FilterGroup $group): array
    {
        return [
            'match' => $group->match,
            'conditions' => array_map(static fn ($condition): array => [
                'field' => $condition->field,
                'operator' => $condition->operator->value,
                'value' => $condition->value,
                'second_value' => $condition->secondValue,
                'selected' => $condition->selected,
            ], $group->conditions),
            'groups' => array_map(
                static fn (FilterGroup $child): array => self::groupToArray($child),
                $group->groups,
            ),
        ];
    }

    /**
     * @return array<int, WorkflowActionData>
     */
    private static function actions(mixed $actions): array
    {
        if (! is_array($actions)) {
            return [];
        }

        $normalised = [];
        $position = 0;

        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            $normalised[] = WorkflowActionData::fromArray($action, $position);
            $position++;
        }

        return $normalised;
    }

    /**
     * A cron expression, but only one the parser can actually read.
     *
     * Stored unparseable, it would throw inside the scheduler command once a
     * minute rather than anywhere somebody would see it.
     */
    private static function schedule(mixed $value): ?string
    {
        $expression = trim((string) $value);

        return $expression !== '' && CronExpression::isValidExpression($expression)
            ? $expression
            : null;
    }

    private static function offset(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function text(array $attributes, string $key): ?string
    {
        $value = trim((string) ($attributes[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
