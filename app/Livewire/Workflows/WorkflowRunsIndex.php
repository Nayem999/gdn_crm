<?php

namespace App\Livewire\Workflows;

use App\Domain\Workflows\Actions\RetryWorkflowRunAction;
use App\Domain\Workflows\Enums\WorkflowRunStatus;
use App\Domain\Workflows\Enums\WorkflowTrigger;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\WorkflowModules;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * What the workflow engine did, and what went wrong.
 *
 * Reading this is its own permission: "why did this record change last night"
 * is a much wider question than "decide what happens tonight", and the people
 * who ask it are not the people who write automations. Retrying is not — it
 * writes to records, so it follows the update permission.
 *
 * Failures are listed first by default, because that is what somebody opens
 * this screen to find.
 */
#[Title('Workflow log')]
class WorkflowRunsIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $module = '';

    #[Url(except: '')]
    public string $workflow = '';

    #[Url(except: '')]
    public string $trigger = '';

    /**
     * Which run's steps are open. One at a time: the detail is long, and a
     * page of expanded runs is not a list any more.
     */
    public ?int $expanded = null;

    public ?string $error = null;

    public int $perPage = 25;

    public function mount(): void
    {
        $this->authorize('viewLog', Workflow::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'module', 'workflow', 'trigger', 'perPage'], true)) {
            $this->resetPage();
            $this->expanded = null;
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['status', 'module', 'workflow', 'trigger']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->status !== '' || $this->module !== '' || $this->workflow !== '' || $this->trigger !== '';
    }

    /**
     * @return LengthAwarePaginator<int, WorkflowRun>
     */
    public function runs(): LengthAwarePaginator
    {
        return WorkflowRun::query()
            ->with(['steps', 'workflow:id,name'])
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->module !== '', fn ($query) => $query->where('module', $this->module))
            ->when($this->workflow !== '', fn ($query) => $query->where('workflow_id', (int) $this->workflow))
            ->when($this->trigger !== '', fn ($query) => $query->where('trigger_event', $this->trigger))
            ->latest('id')
            ->paginate($this->perPage);
    }

    /**
     * How the engine is doing, over the runs currently filtered to.
     *
     * Counted in one grouped query rather than one per status: a log screen
     * that costs five counts per render is a log screen somebody stops opening.
     *
     * @return array<string, int>
     */
    public function tally(): array
    {
        /** @var array<string, int> $counts */
        $counts = WorkflowRun::query()
            ->when($this->module !== '', fn ($query) => $query->where('module', $this->module))
            ->when($this->workflow !== '', fn ($query) => $query->where('workflow_id', (int) $this->workflow))
            ->when($this->trigger !== '', fn ($query) => $query->where('trigger_event', $this->trigger))
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        return $counts;
    }

    /**
     * @return array<string, string>
     */
    public function statusOptions(): array
    {
        return ['' => 'Any outcome', ...WorkflowRunStatus::options()];
    }

    /**
     * @return array<string, string>
     */
    public function moduleOptions(): array
    {
        return ['' => 'Every module', ...WorkflowModules::options()];
    }

    /**
     * @return array<string, string>
     */
    public function triggerOptions(): array
    {
        return ['' => 'Any trigger', ...WorkflowTrigger::options()];
    }

    /**
     * @return array<int|string, string>
     */
    public function workflowOptions(): array
    {
        return ['' => 'Every workflow', ...Workflow::query()->orderBy('name')->pluck('name', 'id')->all()];
    }

    public function expand(int $runId): void
    {
        $this->expanded = $this->expanded === $runId ? null : $runId;
    }

    /**
     * Put one failed run back on the queue.
     */
    public function retry(int $runId): void
    {
        $run = WorkflowRun::query()->with('steps')->whereKey($runId)->first();

        if ($run === null) {
            return;
        }

        // Retrying writes to records, so it is not something a reader of the
        // log may do.
        $this->authorize('retry', Workflow::class);

        try {
            app(RetryWorkflowRunAction::class)($run);
            $this->error = null;
        } catch (RuntimeException $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        $this->dispatch('notify', type: 'success', message: 'Put back on the queue.');
    }

    /**
     * Retry everything currently filtered to that failed.
     *
     * Deliberately bounded and deliberately tied to the filters: "retry all"
     * across a log of a hundred thousand rows is not a button anybody should
     * be able to press by accident.
     */
    public const BULK_LIMIT = 100;

    public function retryFiltered(): void
    {
        $this->authorize('retry', Workflow::class);

        $runs = WorkflowRun::query()
            ->with('steps')
            ->where('status', WorkflowRunStatus::Failed->value)
            ->when($this->module !== '', fn ($query) => $query->where('module', $this->module))
            ->when($this->workflow !== '', fn ($query) => $query->where('workflow_id', (int) $this->workflow))
            ->when($this->trigger !== '', fn ($query) => $query->where('trigger_event', $this->trigger))
            ->orderBy('id')
            ->limit(self::BULK_LIMIT)
            ->get();

        $retried = 0;

        foreach ($runs as $run) {
            try {
                app(RetryWorkflowRunAction::class)($run);
                $retried++;
            } catch (RuntimeException) {
                // A run whose workflow has since been removed cannot be
                // retried. Skipped rather than aborting the batch: one
                // unretryable row must not stop the other ninety-nine.
            }
        }

        $this->dispatch('notify', type: 'success', message: $retried.' '.str('run')->plural($retried).' put back on the queue.');
    }

    public function render(): View
    {
        return view('livewire.workflows.workflow-runs-index', [
            'runs' => $this->runs(),
            'tally' => $this->tally(),
        ]);
    }
}
