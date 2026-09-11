<?php

namespace App\Domain\Workflows;

use App\Domain\Workflows\Enums\WorkflowTrigger;
use App\Domain\Workflows\Models\Workflow;
use Illuminate\Database\Eloquent\Collection;

/**
 * The workflows listening for each kind of event, remembered for one request.
 *
 * Every save of every record asks this question. Without the memo a bulk import
 * of five thousand leads is five thousand identical queries, and the answer
 * cannot have changed between the first and the last — no request both edits a
 * workflow and imports under it.
 *
 * Memoised per request rather than cached across them, and flushed explicitly
 * by the four actions that can change the answer. A cache nobody flushes is how
 * a workflow somebody just switched on does not run until the next deploy.
 *
 * Same arrangement as PipelineStatusCache and CustomFieldSchema.
 */
final class WorkflowCache
{
    /**
     * @var array<string, Collection<int, Workflow>>
     */
    private array $listening = [];

    /**
     * The runnable workflows for a module and trigger, in the order they run.
     *
     * Their steps are eager-loaded, because `isRunnable()` asks about them and
     * the dispatcher would otherwise query once per workflow per saved record.
     *
     * @return Collection<int, Workflow>
     */
    public function listeningFor(string $module, WorkflowTrigger $trigger): Collection
    {
        return $this->listening[$module.':'.$trigger->value] ??= Workflow::query()
            ->listeningFor($module, $trigger)
            ->with('actions')
            ->get()
            ->filter(fn (Workflow $workflow): bool => $workflow->isRunnable())
            ->values();
    }

    public function flush(): void
    {
        $this->listening = [];
    }
}
