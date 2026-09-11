<?php

namespace App\Livewire\Dashboard;

use App\Domain\Activities\Actions\CompleteActivityAction;
use App\Domain\Activities\Models\Activity;
use App\Livewire\Dashboard\Concerns\ScopesToViewer;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The viewer's own open work, soonest first.
 *
 * "My tasks" means **owned by me**, not "everything I can see": a manager with
 * all-records access would otherwise open the dashboard to the whole company's
 * to-do list. The access-level scope is still applied underneath — an owner
 * always falls inside their own scope, so the two agree — because a widget that
 * skipped it would be the one place the rule did not hold.
 */
class DashboardTasks extends Component
{
    use AuthorizesRequests;
    use ScopesToViewer;

    /**
     * How many are listed. Enough to fill the column beside the funnel without
     * turning the dashboard into the activities list, which is one click away.
     */
    public const LIMIT = 8;

    public function canSeeActivities(): bool
    {
        return $this->scope()->activities() !== null;
    }

    /**
     * @return array<int, Activity>
     */
    #[Computed]
    public function tasks(): array
    {
        $activities = $this->scope()->activities();

        if ($activities === null) {
            return [];
        }

        return $activities
            ->open()
            ->where('activities.owner_id', $this->viewer()->id)
            ->with('related')
            // Overdue first, then soonest: a list that buried last week's call
            // under this afternoon's would be sorted correctly and useless.
            ->orderBy('activities.due_at')
            ->orderBy('activities.id')
            ->limit(self::LIMIT)
            ->get()
            ->all();
    }

    #[Computed]
    public function openCount(): int
    {
        $activities = $this->scope()->activities();

        if ($activities === null) {
            return 0;
        }

        return $activities->open()->where('activities.owner_id', $this->viewer()->id)->count();
    }

    /**
     * Tick one off without leaving the dashboard.
     *
     * Loaded through the scoped query and authorised, so an id typed into the
     * payload reaches nothing the person could not already open.
     */
    public function complete(int $activityId): void
    {
        $activities = $this->scope()->activities();

        if ($activities === null) {
            return;
        }

        $activity = $activities->whereKey($activityId)->first();

        if ($activity === null) {
            return;
        }

        $this->authorize('update', $activity);

        app(CompleteActivityAction::class)($activity);

        unset($this->tasks, $this->openCount);

        $this->dispatch('notify', type: 'success', message: $activity->subject.' is done.');
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="rounded-xl border border-border bg-card p-5" aria-hidden="true">
            <div class="h-4 w-24 animate-pulse rounded bg-muted"></div>
            <div class="mt-5 space-y-3">
                <div class="h-10 animate-pulse rounded-lg bg-muted"></div>
                <div class="h-10 animate-pulse rounded-lg bg-muted"></div>
                <div class="h-10 animate-pulse rounded-lg bg-muted"></div>
                <div class="h-10 animate-pulse rounded-lg bg-muted"></div>
            </div>
            <span class="sr-only">Loading your tasks…</span>
        </div>
        HTML;
    }

    public function render(): View
    {
        return view('livewire.dashboard.dashboard-tasks');
    }
}
