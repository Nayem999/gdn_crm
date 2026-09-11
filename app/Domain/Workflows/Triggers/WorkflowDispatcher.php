<?php

namespace App\Domain\Workflows\Triggers;

use App\Domain\Workflows\Actions\StartWorkflowRunAction;
use App\Domain\Workflows\Enums\WorkflowTrigger;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\WorkflowCache;
use App\Domain\Workflows\WorkflowModules;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns something that happened into runs.
 *
 * The one place that answers "what should fire now". The observers, the date
 * sweep and the scheduler all come through here, so the rules about what fires
 * — suppression, per-record limits, which field changed — are written once
 * rather than three times with two of them slightly different.
 */
class WorkflowDispatcher
{
    public function __construct(
        private readonly WorkflowCache $cache,
        private readonly WorkflowSuppressor $suppressor,
        private readonly StartWorkflowRunAction $start,
    ) {}

    /**
     * Something happened to a record.
     *
     * @param  array<string, mixed>  $context  What the trigger saw — for a
     *                                         change, the fields and their old values.
     * @return array<int, WorkflowRun>
     */
    public function record(Model $record, WorkflowTrigger $trigger, array $context = []): array
    {
        // A write made by a workflow action raises nothing. See
        // WorkflowSuppressor for why this is blunt rather than clever.
        if ($this->suppressor->isSuppressed()) {
            return [];
        }

        $module = WorkflowModules::keyFor($record);

        if ($module === null) {
            return [];
        }

        $runs = [];

        foreach ($this->cache->listeningFor($module, $trigger) as $workflow) {
            if (! $this->watchesThisChange($workflow, $trigger, $context)) {
                continue;
            }

            $run = ($this->start)(
                $workflow,
                $trigger,
                $record,
                $context,
                $this->keyFor($workflow, $trigger, $record),
            );

            if ($run !== null) {
                $runs[] = $run;
            }
        }

        return $runs;
    }

    /**
     * A workflow fired by the clock rather than by a record.
     *
     * @param  array<string, mixed>  $context
     */
    public function occasion(Workflow $workflow, string $dedupeKey, array $context = []): ?WorkflowRun
    {
        if ($this->suppressor->isSuppressed()) {
            return null;
        }

        return ($this->start)($workflow, $workflow->trigger(), null, $context, $dedupeKey);
    }

    /**
     * A date workflow reaching one record's moment.
     *
     * Its own entry point because the dedupe key is different in kind: a date
     * arrives once per record for good, so the claim is permanent rather than
     * per-occasion.
     *
     * @param  array<string, mixed>  $context
     */
    public function dateReached(Workflow $workflow, Model $record, array $context = []): ?WorkflowRun
    {
        if ($this->suppressor->isSuppressed()) {
            return null;
        }

        return ($this->start)(
            $workflow,
            WorkflowTrigger::DateReached,
            $record,
            $context,
            $this->keyFor($workflow, WorkflowTrigger::DateReached, $record),
        );
    }

    /**
     * Whether a field-change workflow cares about the fields that changed.
     *
     * Every other trigger cares about the event itself, so this only has
     * something to say about one of them.
     *
     * @param  array<string, mixed>  $context
     */
    private function watchesThisChange(Workflow $workflow, WorkflowTrigger $trigger, array $context): bool
    {
        if ($trigger !== WorkflowTrigger::FieldChanged) {
            return true;
        }

        $changed = $context['changed'] ?? [];

        return is_array($changed) && array_key_exists((string) $workflow->trigger_field, $changed);
    }

    /**
     * What makes this occasion unique, or null when there is nothing to claim.
     *
     * The keys say what "exactly once" means for each trigger, and they are
     * different on purpose:
     *
     * - A record is **created** and **deleted** once, so those claim the record
     *   permanently — which is what makes a retried job safe.
     * - A **date** arrives once per record, so the same.
     * - An **update** or a **field change** happens as often as somebody edits.
     *   Each is its own occasion, so there is nothing to claim: the guarantee
     *   that it fires once per edit comes from the observer running once per
     *   save, not from a key.
     */
    private function keyFor(Workflow $workflow, WorkflowTrigger $trigger, Model $record): ?string
    {
        return match ($trigger) {
            WorkflowTrigger::RecordCreated,
            WorkflowTrigger::RecordDeleted,
            WorkflowTrigger::DateReached => sprintf(
                'w%d:%s:%s:%s',
                $workflow->id,
                $record->getMorphClass(),
                (string) $record->getKey(),
                $trigger->value,
            ),
            default => null,
        };
    }
}
