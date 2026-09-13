<?php

namespace App\Livewire\Reports;

use App\Domain\Reports\Actions\DeleteReportAction;
use App\Domain\Reports\Actions\DuplicateReportAction;
use App\Domain\Reports\Models\Report;
use App\Domain\Reports\ReportSources;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * The saved questions.
 *
 * Deliberately not the data-view kit: there are rarely more than a few dozen
 * reports, they are read by name rather than filtered, and the useful split is
 * "mine" against "shared" rather than any column.
 */
#[Title('Reports')]
class ReportsIndex extends Component
{
    use AuthorizesRequests;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'source', except: '')]
    public string $sourceFilter = '';

    #[Url(as: 'scope', except: '')]
    public string $scope = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Report::class);
    }

    /**
     * @return Collection<int, Report>
     */
    #[Computed]
    public function reports(): Collection
    {
        $user = $this->currentUser();

        return Report::query()
            ->visibleTo($user)
            ->with('owner:id,name')
            ->search(trim($this->search))
            ->when($this->sourceFilter !== '', fn ($query) => $query->where('source', $this->sourceFilter))
            ->when($this->scope === 'mine', fn ($query) => $query->where('owner_id', $user->id))
            ->when($this->scope === 'shared', fn ($query) => $query->where('is_shared', true))
            ->when($this->scope === 'standard', fn ($query) => $query->where('is_standard', true))
            ->orderByDesc('is_standard')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    public function sourceOptions(): array
    {
        return ReportSources::optionsFor($this->currentUser());
    }

    public function duplicate(int $id): void
    {
        $report = $this->find($id);

        if ($report === null) {
            return;
        }

        $this->authorize('create', Report::class);
        $this->authorize('view', $report);

        $copy = app(DuplicateReportAction::class)($report, $this->currentUser());

        $this->redirectRoute('reports.edit', ['report' => $copy->id], navigate: true);
    }

    public function delete(int $id): void
    {
        $report = $this->find($id);

        if ($report === null) {
            return;
        }

        $this->authorize('delete', $report);

        try {
            app(DeleteReportAction::class)($report);
        } catch (RuntimeException $exception) {
            $this->dispatch('notify', type: 'error', message: $exception->getMessage());

            return;
        }

        unset($this->reports);

        $this->dispatch('notify', type: 'success', message: $report->name.' has been removed.');
    }

    public function setScope(string $scope): void
    {
        $this->scope = in_array($scope, ['mine', 'shared', 'standard'], true) && $this->scope !== $scope
            ? $scope
            : '';

        unset($this->reports);
    }

    public function updatedSearch(): void
    {
        unset($this->reports);
    }

    public function updatedSourceFilter(): void
    {
        unset($this->reports);
    }

    /**
     * Scoped before the policy is asked, so a guessed id from somebody else's
     * private report is not even loaded.
     */
    private function find(int $id): ?Report
    {
        return Report::query()->visibleTo($this->currentUser())->whereKey($id)->first();
    }

    private function currentUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    public function render(): View
    {
        return view('livewire.reports.reports-index');
    }
}
