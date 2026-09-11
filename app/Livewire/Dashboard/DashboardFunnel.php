<?php

namespace App\Livewire\Dashboard;

use App\Domain\Dashboard\FunnelStage;
use App\Domain\Dashboard\PipelineFunnel;
use App\Domain\Deals\Models\Pipeline;
use App\Livewire\Dashboard\Concerns\ScopesToViewer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Deals by stage, for one pipeline.
 *
 * The selector carries a pipeline **id**, and it is resolved against the
 * pipelines that exist rather than trusted: an id the list does not hold falls
 * back to the default one, so a pasted URL cannot draw a funnel for something
 * that is not there.
 */
class DashboardFunnel extends Component
{
    use ScopesToViewer;

    #[Url(as: 'pipeline', except: 0)]
    public int $pipelineId = 0;

    public function canSeeDeals(): bool
    {
        return $this->scope()->canSee('deals');
    }

    /**
     * The pipeline on screen, or null when none is configured.
     */
    #[Computed]
    public function pipeline(): ?Pipeline
    {
        $pipelines = $this->pipelines();

        if ($pipelines === []) {
            return null;
        }

        foreach ($pipelines as $pipeline) {
            if ($pipeline->getKey() === $this->pipelineId) {
                return $pipeline;
            }
        }

        // Pipeline::default() falls back to the first by position rather than
        // returning null, so a database that lost the flag still draws — see
        // .ai/rules/pipelines.md.
        return Pipeline::default() ?? $pipelines[0];
    }

    /**
     * @return array<int, Pipeline>
     */
    #[Computed]
    public function pipelines(): array
    {
        return app(PipelineFunnel::class)->pipelines();
    }

    /**
     * @return array<int, FunnelStage>
     */
    #[Computed]
    public function bands(): array
    {
        $pipeline = $this->pipeline();

        if ($pipeline === null || ! $this->canSeeDeals()) {
            return [];
        }

        return app(PipelineFunnel::class)->for($this->scope(), $pipeline);
    }

    public function selectPipeline(int $pipelineId): void
    {
        foreach ($this->pipelines() as $pipeline) {
            if ($pipeline->getKey() === $pipelineId) {
                $this->pipelineId = $pipelineId;
                unset($this->pipeline, $this->bands);

                return;
            }
        }
    }

    /**
     * @return array<int, string>
     */
    public function pipelineOptions(): array
    {
        $options = [];

        foreach ($this->pipelines() as $pipeline) {
            $options[$pipeline->getKey()] = $pipeline->displayName();
        }

        return $options;
    }

    public function totalDeals(): int
    {
        return array_sum(array_map(fn (FunnelStage $band): int => $band->count, $this->bands()));
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="rounded-xl border border-border bg-card p-5" aria-hidden="true">
            <div class="h-4 w-32 animate-pulse rounded bg-muted"></div>
            <div class="mt-5 space-y-4">
                <div class="h-6 w-full animate-pulse rounded bg-muted"></div>
                <div class="h-6 w-5/6 animate-pulse rounded bg-muted"></div>
                <div class="h-6 w-3/5 animate-pulse rounded bg-muted"></div>
                <div class="h-6 w-2/5 animate-pulse rounded bg-muted"></div>
            </div>
            <span class="sr-only">Loading the funnel…</span>
        </div>
        HTML;
    }

    public function render(): View
    {
        return view('livewire.dashboard.dashboard-funnel');
    }
}
