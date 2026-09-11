<?php

namespace App\Livewire\Deals;

use App\Domain\Deals\Actions\DeletePipelineAction;
use App\Domain\Deals\Actions\ReorderPipelinesAction;
use App\Domain\Deals\Actions\SetDefaultPipelineAction;
use App\Domain\Deals\Models\Pipeline;
use App\Domain\Deals\PipelineModules;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * The pipelines an installation offers, in the order they are presented.
 */
#[Title('Pipelines')]
class PipelinesIndex extends Component
{
    use AuthorizesRequests;

    /**
     * Which module's pipelines are on screen. Matched against the registry, so
     * a pasted URL cannot name a module that has none.
     */
    #[Url(as: 'module', except: 'deals')]
    public string $module = 'deals';

    public function mount(): void
    {
        $this->authorize('viewAny', Pipeline::class);

        if (! PipelineModules::has($this->module)) {
            $this->module = Pipeline::DEALS;
        }
    }

    public function selectModule(string $module): void
    {
        if (PipelineModules::has($module)) {
            $this->module = $module;
        }
    }

    /**
     * @return array<string, string>
     */
    public function moduleOptions(): array
    {
        return PipelineModules::options();
    }

    /**
     * Whether this module's records still fall back to their own enum because
     * nothing is configured for it yet.
     */
    public function usesFallback(): bool
    {
        return ! PipelineModules::isConfigured($this->module);
    }

    /**
     * @return Collection<int, Pipeline>
     */
    public function pipelines(): Collection
    {
        return Pipeline::query()
            ->forModule($this->module)
            ->with('stages')
            // Only deals hang off a pipeline by foreign key; every other module
            // matches on the stage key it stores, so there is nothing to count.
            ->when($this->module === Pipeline::DEALS, fn ($query) => $query->withCount('deals'))
            ->ordered()
            ->get();
    }

    /**
     * The order the drag left them in.
     *
     * @param  array<int, int|string>  $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        $this->authorize('create', Pipeline::class);

        app(ReorderPipelinesAction::class)($orderedIds, $this->module);
    }

    public function makeDefault(int $pipelineId): void
    {
        $pipeline = $this->pipeline($pipelineId);

        $this->authorize('update', $pipeline);

        app(SetDefaultPipelineAction::class)($pipeline);

        $this->dispatch('notify', type: 'success', message: $pipeline->name.' is now the default.');
    }

    public function delete(int $pipelineId): void
    {
        $pipeline = $this->pipeline($pipelineId);

        $this->authorize('delete', $pipeline);

        try {
            app(DeletePipelineAction::class)($pipeline);
        } catch (RuntimeException $exception) {
            $this->dispatch('notify', type: 'error', message: $exception->getMessage());

            return;
        }

        $this->dispatch('notify', type: 'success', message: $pipeline->name.' was removed.');
    }

    private function pipeline(int $pipelineId): Pipeline
    {
        // Scoped to the module on screen, so a payload cannot reach a pipeline
        // the page never showed.
        $pipeline = Pipeline::query()->forModule($this->module)->whereKey($pipelineId)->first();

        if ($pipeline === null) {
            abort(404);
        }

        return $pipeline;
    }

    public function render(): View
    {
        return view('livewire.deals.pipelines-index', [
            'pipelines' => $this->pipelines(),
        ]);
    }
}
