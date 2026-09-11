<?php

namespace App\Livewire\Dashboard;

use App\Domain\Dashboard\DashboardFeed;
use App\Domain\Timeline\TimelineEntry;
use App\Livewire\Dashboard\Concerns\ScopesToViewer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * What has been happening, across every module this person can see.
 *
 * Scoped, unlike the audit viewer it shares its rows with — see DashboardFeed.
 */
class DashboardActivityFeed extends Component
{
    use ScopesToViewer;

    public int $visible = DashboardFeed::DEFAULT_LIMIT;

    public const PAGE_SIZE = DashboardFeed::DEFAULT_LIMIT;

    /**
     * The ceiling on "show more". A dashboard widget is a glance, not an
     * archive: past this, the audit viewer is the screen for the job.
     */
    public const MAX_VISIBLE = 48;

    /**
     * @return array<int, TimelineEntry>
     */
    #[Computed]
    public function entries(): array
    {
        return app(DashboardFeed::class)->for($this->scope(), $this->visible);
    }

    public function hasMore(): bool
    {
        return $this->visible < self::MAX_VISIBLE
            && count($this->entries()) >= $this->visible;
    }

    public function loadMore(): void
    {
        $this->visible = min(self::MAX_VISIBLE, $this->visible + self::PAGE_SIZE);
        unset($this->entries);
    }

    public function seesAnything(): bool
    {
        return $this->scope()->seesAnything();
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="rounded-xl border border-border bg-card p-5" aria-hidden="true">
            <div class="h-4 w-28 animate-pulse rounded bg-muted"></div>
            <div class="mt-5 space-y-5">
                <div class="flex gap-3">
                    <div class="h-8 w-8 shrink-0 animate-pulse rounded-full bg-muted"></div>
                    <div class="flex-1 space-y-2">
                        <div class="h-3 w-2/3 animate-pulse rounded bg-muted"></div>
                        <div class="h-3 w-1/3 animate-pulse rounded bg-muted"></div>
                    </div>
                </div>
                <div class="flex gap-3">
                    <div class="h-8 w-8 shrink-0 animate-pulse rounded-full bg-muted"></div>
                    <div class="flex-1 space-y-2">
                        <div class="h-3 w-3/4 animate-pulse rounded bg-muted"></div>
                        <div class="h-3 w-1/4 animate-pulse rounded bg-muted"></div>
                    </div>
                </div>
                <div class="flex gap-3">
                    <div class="h-8 w-8 shrink-0 animate-pulse rounded-full bg-muted"></div>
                    <div class="flex-1 space-y-2">
                        <div class="h-3 w-1/2 animate-pulse rounded bg-muted"></div>
                        <div class="h-3 w-1/3 animate-pulse rounded bg-muted"></div>
                    </div>
                </div>
            </div>
            <span class="sr-only">Loading recent activity…</span>
        </div>
        HTML;
    }

    public function render(): View
    {
        return view('livewire.dashboard.dashboard-activity-feed');
    }
}
