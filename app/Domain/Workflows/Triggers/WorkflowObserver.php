<?php

namespace App\Domain\Workflows\Triggers;

use App\Domain\Workflows\Enums\WorkflowTrigger;
use App\Domain\Workflows\WorkflowModules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Watches the modules workflows can be attached to.
 *
 * Registered once per model class in AppServiceProvider, from the registry's
 * own list, so a module cannot be watched without being a module.
 *
 * **Dispatch waits for the commit; the decision does not.** A lead created
 * inside a transaction that then rolls back never existed, and a workflow that
 * had already fired for it would be acting on a record nobody can look at — lead
 * conversion is exactly such a transaction. So the run is started from a
 * `DB::afterCommit` callback.
 *
 * But suppression has to be read *here*, while the write is happening.
 * `ShouldHandleEventsAfterCommit` was the obvious way to write this and it is
 * wrong for exactly that reason: the handler then runs after the action that
 * suppressed triggers has already finished, sees suppression switched off, and
 * the loop 5.4 is trying to avoid happens anyway.
 *
 * An update fires two triggers, not one: `record_updated` for workflows
 * watching the record, and `field_changed` for those watching one of the fields
 * that actually moved. They are separate triggers with separate workflows
 * behind them, so a workflow never sees the same edit twice.
 */
class WorkflowObserver
{
    public function __construct(
        private readonly WorkflowDispatcher $dispatcher,
        private readonly WorkflowSuppressor $suppressor,
    ) {}

    public function created(Model $record): void
    {
        $this->afterCommit(fn () => $this->dispatcher->record($record, WorkflowTrigger::RecordCreated));
    }

    public function updated(Model $record): void
    {
        // Computed now, while the model still knows what this save changed.
        $context = ['changed' => $this->changes($record)];

        if ($context['changed'] === []) {
            return;
        }

        $this->afterCommit(function () use ($record, $context) {
            $this->dispatcher->record($record, WorkflowTrigger::RecordUpdated, $context);
            $this->dispatcher->record($record, WorkflowTrigger::FieldChanged, $context);
        });
    }

    public function deleted(Model $record): void
    {
        $this->afterCommit(fn () => $this->dispatcher->record($record, WorkflowTrigger::RecordDeleted));
    }

    /**
     * Defer to the commit, having decided here whether to defer at all.
     *
     * A callback registered inside a transaction that rolls back is discarded,
     * which is the whole point; outside one it runs immediately.
     */
    private function afterCommit(callable $dispatch): void
    {
        if ($this->suppressor->isSuppressed()) {
            return;
        }

        DB::afterCommit($dispatch);
    }

    /**
     * What changed, as field keys rather than column names.
     *
     * Keyed the way a workflow stores its watched field, because that is what
     * the dispatcher compares against. Fields the module does not offer are
     * dropped: a workflow cannot watch one, so reporting it would only put
     * columns nobody chose into the log.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changes(Model $record): array
    {
        $module = WorkflowModules::keyFor($record);

        if ($module === null) {
            return [];
        }

        $original = $record->getRawOriginal();
        $changes = [];

        foreach (WorkflowModules::fields($module) as $key => $field) {
            // A custom field's answers are not columns on this model, so a save
            // of the record never reports them as changed. 4.1 values are
            // written separately, and watching one is a later problem than this.
            if ($field->isCustomField()) {
                continue;
            }

            $column = $field->column();

            if (! array_key_exists($column, $record->getChanges())) {
                continue;
            }

            $changes[$key] = [
                'from' => $original[$column] ?? null,
                'to' => $record->getChanges()[$column],
            ];
        }

        return $changes;
    }
}
