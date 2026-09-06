<?php

namespace App\Livewire\Deals;

use App\Domain\Deals\Actions\DeletePipelineAction;
use App\Domain\Deals\Actions\ReorderPipelinesAction;
use App\Domain\Deals\Actions\SetDefaultPipelineAction;
use App\Domain\Deals\Models\Pipeline;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * The pipelines an installation offers, in the order they are presented.
 */
#[Title('Pipelines')]
class PipelinesIndex extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('viewAny', Pipeline::class);
    }

    /**
     * @return Collection<int, Pipeline>
     */
    public function pipelines(): Collection
    {
        return Pipeline::query()
            ->with('stages')
            ->withCount('deals')
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

        app(ReorderPipelinesAction::class)($orderedIds);
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
        $pipeline = Pipeline::query()->whereKey($pipelineId)->first();

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
