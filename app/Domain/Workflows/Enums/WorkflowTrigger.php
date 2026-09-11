<?php

namespace App\Domain\Workflows\Enums;

/**
 * What starts a workflow.
 *
 * Each case says what the engine (5.2) has to watch and what it needs
 * configured alongside it. A trigger that needs a field says so here rather
 * than in the screen that edits it, so the validation and the builder cannot
 * disagree about which combinations are coherent.
 */
enum WorkflowTrigger: string
{
    case RecordCreated = 'record_created';
    case RecordUpdated = 'record_updated';
    case FieldChanged = 'field_changed';
    case RecordDeleted = 'record_deleted';
    case DateReached = 'date_reached';
    case Scheduled = 'scheduled';

    public function label(): string
    {
        return match ($this) {
            self::RecordCreated => 'When a record is created',
            self::RecordUpdated => 'When a record is updated',
            self::FieldChanged => 'When a field changes',
            self::RecordDeleted => 'When a record is deleted',
            self::DateReached => 'When a date arrives',
            self::Scheduled => 'On a schedule',
        };
    }

    /**
     * Whether this trigger is meaningless without a field named alongside it.
     *
     * Both cases that need one need it for different reasons — a change to
     * watch, a date to count from — but both are unusable without it, which is
     * the only thing a caller validating a definition cares about.
     */
    public function needsField(): bool
    {
        return $this === self::FieldChanged || $this === self::DateReached;
    }

    /**
     * Whether the field must be a date, as opposed to any field at all.
     */
    public function needsDateField(): bool
    {
        return $this === self::DateReached;
    }

    /**
     * Whether this trigger is meaningless without a schedule to run on.
     */
    public function needsSchedule(): bool
    {
        return $this === self::Scheduled;
    }

    /**
     * Whether a firing is about one record.
     *
     * A scheduled workflow is the exception: it fires because the clock said
     * so, then decides for itself which records it is about. That is why
     * `workflow_runs.subject_id` is nullable.
     */
    public function hasSubject(): bool
    {
        return $this !== self::Scheduled;
    }

    /**
     * Whether the record still exists by the time actions run.
     *
     * A delete trigger fires on a record that is on its way out, so an action
     * that writes to it has nothing to write to. 5.4 uses this to decide which
     * actions a trigger may be paired with.
     */
    public function subjectSurvives(): bool
    {
        return $this !== self::RecordDeleted;
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
