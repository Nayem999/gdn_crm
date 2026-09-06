<?php

namespace App\Livewire\Audit;

use App\Domain\Audit\ActivityPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

#[Title('Audit log')]
class ActivityLogIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $event = '';

    #[Url(except: '')]
    public string $subjectType = '';

    #[Url(except: 25)]
    public int $perPage = 25;

    /**
     * Ids of rows whose before/after detail is expanded.
     *
     * @var array<int, int>
     */
    public array $expanded = [];

    public function mount(): void
    {
        $this->authorize('viewAny', Activity::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedEvent(): void
    {
        $this->resetPage();
    }

    public function updatedSubjectType(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'event', 'subjectType']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->event !== '' || $this->subjectType !== '';
    }

    public function toggle(int $activityId): void
    {
        $this->expanded = in_array($activityId, $this->expanded, true)
            ? array_values(array_diff($this->expanded, [$activityId]))
            : [...$this->expanded, $activityId];
    }

    public function isExpanded(int $activityId): bool
    {
        return in_array($activityId, $this->expanded, true);
    }

    /**
     * @return LengthAwarePaginator<int, Activity>
     */
    public function activities(): LengthAwarePaginator
    {
        return Activity::query()
            ->with(['causer', 'subject'])
            ->when($this->event !== '', fn ($query) => $query->where('event', $this->event))
            ->when($this->subjectType !== '', fn ($query) => $query->where('subject_type', $this->subjectType))
            ->when($this->search !== '', function ($query) {
                $term = '%'.$this->search.'%';

                $query->where(function ($inner) use ($term) {
                    $inner->where('description', 'like', $term)
                        ->orWhereHas('causer', fn ($causer) => $causer->where('name', 'like', $term)
                            ->orWhere('email', 'like', $term));
                });
            })
            // id is a tiebreaker, not decoration: entries written in the same
            // second would otherwise come back in arbitrary order, which also
            // makes pagination unstable enough to repeat or skip rows.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage);
    }

    /**
     * Event values actually present in the log, so the filter never offers an
     * option that returns nothing.
     *
     * @return array<string, string>
     */
    public function eventOptions(): array
    {
        return Activity::query()
            ->whereNotNull('event')
            ->distinct()
            ->orderBy('event')
            ->pluck('event', 'event')
            ->map(fn (string $event) => str($event)->headline()->toString())
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function subjectTypeOptions(): array
    {
        return Activity::query()
            ->whereNotNull('subject_type')
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type', 'subject_type')
            ->map(fn (string $type) => str(class_basename($type))->headline()->toString())
            ->all();
    }

    public function eventColor(?string $event): string
    {
        return ActivityPresenter::color($event);
    }

    /**
     * The before/after pairs for one entry, keyed by attribute.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function changesFor(Activity $activity): array
    {
        return ActivityPresenter::changes($activity);
    }

    /**
     * Render a logged value for display, including arrays such as a role's
     * permission list.
     */
    public function formatValue(mixed $value): string
    {
        return ActivityPresenter::value($value);
    }

    public function render(): View
    {
        return view('livewire.audit.activity-log-index', ['activities' => $this->activities()]);
    }
}
