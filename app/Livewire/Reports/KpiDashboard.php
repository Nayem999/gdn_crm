<?php

namespace App\Livewire\Reports;

use App\Domain\Reports\Enums\ChartType;
use App\Domain\Reports\Models\DashboardWidget;
use App\Domain\Reports\Models\Report;
use App\Domain\Reports\ReportDefinition;
use App\Domain\Reports\ReportResult;
use App\Domain\Reports\ReportRunner;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A dashboard of saved reports, arranged by the person looking at it.
 *
 * Each widget is run **as the viewer**, so a shared report on two people's
 * dashboards shows each of them their own figures. That is also why the layout
 * is per user and there is no shared dashboard: a shared arrangement would
 * raise the question of whose records it counts, and the honest answer is
 * already what this is.
 */
#[Title('KPI dashboard')]
class KpiDashboard extends Component
{
    use AuthorizesRequests;

    /**
     * How many widgets one dashboard holds.
     *
     * Each widget is a query, so a dashboard is as expensive as its length.
     * Beyond a dozen nobody reads it and the page takes a noticeable moment.
     */
    public const MAX_WIDGETS = 12;

    public bool $arranging = false;

    public string $addReportId = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Report::class);
    }

    /**
     * @return Collection<int, DashboardWidget>
     */
    #[Computed]
    public function widgets(): Collection
    {
        return DashboardWidget::query()
            ->forUser($this->currentUser())
            ->with('report')
            ->ordered()
            ->get();
    }

    /**
     * The reports that could be added: visible, not already on the dashboard,
     * and about something this person can actually see.
     *
     * @return array<int, string>
     */
    public function addableReports(): array
    {
        $user = $this->currentUser();
        $taken = $this->widgets()->pluck('report_id')->all();

        $options = [];

        foreach (Report::query()->visibleTo($user)->orderBy('name')->get() as $report) {
            if (in_array($report->id, $taken, true) || ! $report->runnableBy($user)) {
                continue;
            }

            $options[$report->id] = $report->name;
        }

        return $options;
    }

    public function isFull(): bool
    {
        return $this->widgets()->count() >= self::MAX_WIDGETS;
    }

    /**
     * One widget's figures, run as the viewer.
     */
    public function resultFor(DashboardWidget $widget): ReportResult
    {
        $report = $widget->report;

        if ($report === null) {
            return ReportResult::refused(ReportDefinition::fromArray(['source' => '']));
        }

        return app(ReportRunner::class)->run($report->definition(), $this->currentUser());
    }

    // -- Arranging -----------------------------------------------------------

    public function add(): void
    {
        $user = $this->currentUser();
        $report = Report::query()->visibleTo($user)->whereKey((int) $this->addReportId)->first();

        if ($report === null || $this->isFull()) {
            return;
        }

        // Checked again here, not only when building the list: the id arrives
        // from the browser.
        $this->authorize('view', $report);

        DashboardWidget::query()->firstOrCreate(
            ['user_id' => $user->id, 'report_id' => $report->id],
            ['position' => $this->widgets()->count()],
        );

        $this->addReportId = '';
        unset($this->widgets);
    }

    public function remove(int $widgetId): void
    {
        $this->widget($widgetId)?->delete();

        unset($this->widgets);
    }

    /**
     * What a drag reports back.
     *
     * Written in one statement per row inside a transaction: a dashboard
     * half-reordered because the second update failed is a layout nobody can
     * fix without dragging everything again.
     *
     * @param  array<int, string|int>  $order  Widget ids, in their new order.
     */
    public function reorder(array $order): void
    {
        $widgets = $this->widgets()->keyBy('id');
        $position = 0;
        $moves = [];

        foreach ($order as $id) {
            $widget = $widgets->get((int) $id);

            // Only this person's widgets, and each one once: the order arrives
            // from the browser.
            if ($widget !== null && ! array_key_exists($widget->id, $moves)) {
                $moves[$widget->id] = $position++;
            }
        }

        // Anything the drag did not mention keeps its place at the end rather
        // than collapsing to nought and jumping to the front.
        foreach ($widgets as $widget) {
            if (! array_key_exists($widget->id, $moves)) {
                $moves[$widget->id] = $position++;
            }
        }

        DB::transaction(function () use ($moves) {
            foreach ($moves as $id => $to) {
                DashboardWidget::query()->whereKey($id)->update(['position' => $to]);
            }
        });

        unset($this->widgets);
    }

    public function resize(int $widgetId, int $width): void
    {
        $widget = $this->widget($widgetId);

        if ($widget === null) {
            return;
        }

        $widget->forceFill(['width' => max(1, min(DashboardWidget::MAX_WIDTH, $width))])->save();

        unset($this->widgets);
    }

    public function setChart(int $widgetId, string $chartType): void
    {
        $widget = $this->widget($widgetId);

        if ($widget === null) {
            return;
        }

        $type = ChartType::tryFrom($chartType);

        $widget->forceFill([
            // An empty choice means "however the report says", which is not the
            // same as a table — the report may be a pie.
            'chart_type' => $type?->value,
        ])->save();

        unset($this->widgets);
    }

    public function toggleArranging(): void
    {
        $this->arranging = ! $this->arranging;
    }

    /**
     * @return array<string, string>
     */
    public function chartOptions(): array
    {
        return ChartType::options();
    }

    /**
     * Scoped to this person before anything else: a widget id from somebody
     * else's dashboard is not even loaded.
     */
    private function widget(int $id): ?DashboardWidget
    {
        return DashboardWidget::query()
            ->forUser($this->currentUser())
            ->whereKey($id)
            ->first();
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
        return view('livewire.reports.kpi-dashboard');
    }
}
