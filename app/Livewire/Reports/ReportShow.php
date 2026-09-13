<?php

namespace App\Livewire\Reports;

use App\Domain\Reports\Enums\ChartType;
use App\Domain\Reports\Models\Report;
use App\Domain\Reports\ReportResult;
use App\Domain\Reports\ReportRunner;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One saved report, run.
 *
 * Run as the **viewer**, never as the author: two people opening the same
 * shared report see different numbers because they can see different records,
 * and that is the correct behaviour rather than a bug to paper over.
 */
class ReportShow extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $reportId;

    public function mount(Report $report): void
    {
        $this->authorize('view', $report);

        $this->reportId = $report->id;

        // Stamped so the list can say which reports nobody opens. saveQuietly,
        // because opening a report is not a change to it and should not fill
        // the audit log.
        $report->forceFill(['last_run_at' => now()])->saveQuietly();
    }

    public function report(): Report
    {
        return Report::query()->with('owner:id,name')->findOrFail($this->reportId);
    }

    #[Computed]
    public function result(): ReportResult
    {
        return app(ReportRunner::class)->run($this->report()->definition(), $this->currentUser());
    }

    public function chart(): ChartType
    {
        return ChartType::tryFrom($this->report()->chart_type) ?? ChartType::Table;
    }

    public function canEdit(): bool
    {
        return auth()->user()?->can('update', $this->report()) === true;
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
        $report = $this->report();

        return view('livewire.reports.report-show', ['report' => $report])
            ->title($report->name);
    }
}
