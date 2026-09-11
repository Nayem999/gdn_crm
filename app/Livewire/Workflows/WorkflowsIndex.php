<?php

namespace App\Livewire\Workflows;

use App\Domain\Workflows\Actions\DeleteWorkflowAction;
use App\Domain\Workflows\Actions\ReorderWorkflowsAction;
use App\Domain\Workflows\Actions\ToggleWorkflowAction;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\WorkflowModules;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * Every workflow, grouped by the module it watches.
 *
 * Grouped rather than one flat list because the order only means anything
 * within a module: two workflows on leads run in the order shown, and a deals
 * workflow's position says nothing about either of them.
 */
#[Title('Workflows')]
class WorkflowsIndex extends Component
{
    use AuthorizesRequests;

    public string $module = '';

    public ?string $error = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Workflow::class);
    }

    /**
     * @return Collection<int, Workflow>
     */
    #[Computed]
    public function workflows(): Collection
    {
        return Workflow::query()
            ->with(['actions', 'author:id,name'])
            ->withCount('runs')
            ->when($this->module !== '', fn ($query) => $query->where('module', $this->module))
            ->orderBy('module')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    public function moduleOptions(): array
    {
        return ['' => 'Every module', ...WorkflowModules::options()];
    }

    public function toggle(int $workflowId): void
    {
        $workflow = Workflow::query()->whereKey($workflowId)->first();

        if ($workflow === null) {
            return;
        }

        $this->authorize('toggle', $workflow);

        try {
            app(ToggleWorkflowAction::class)($workflow, ! $workflow->is_active);
            $this->error = null;
        } catch (RuntimeException $refused) {
            // Switching one on is the moment it starts changing records, so a
            // definition that could not run is refused with the reason rather
            // than switched on and left silently doing nothing.
            $this->error = $refused->getMessage();

            return;
        }

        unset($this->workflows);
    }

    public function delete(int $workflowId): void
    {
        $workflow = Workflow::query()->whereKey($workflowId)->first();

        if ($workflow === null) {
            return;
        }

        $this->authorize('delete', $workflow);

        $name = $workflow->name;
        app(DeleteWorkflowAction::class)($workflow);

        unset($this->workflows);

        $this->dispatch('notify', type: 'success', message: $name.' was removed. Its history is kept.');
    }

    /**
     * @param  array<int, int|string>  $orderedIds
     */
    public function reorder(string $module, array $orderedIds): void
    {
        $this->authorize('create', Workflow::class);

        app(ReorderWorkflowsAction::class)($module, $orderedIds);

        unset($this->workflows);
    }

    public function render(): View
    {
        return view('livewire.workflows.workflows-index');
    }
}
