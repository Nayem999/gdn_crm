<?php

namespace App\Livewire\Reports;

use App\Domain\Reports\Actions\SaveReportAction;
use App\Domain\Reports\Enums\ChartType;
use App\Domain\Reports\Enums\DateGrain;
use App\Domain\Reports\Enums\DatePeriod;
use App\Domain\Reports\Models\Report;
use App\Domain\Reports\ReportDefinition;
use App\Domain\Reports\ReportResult;
use App\Domain\Reports\ReportRunner;
use App\Domain\Reports\ReportSource;
use App\Domain\Reports\ReportSources;
use App\Domain\Shared\Concerns\EditsConditions;
use App\Domain\Shared\Filters\FilterField;
use App\Domain\Shared\Filters\FilterGroup;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * Build a question by dragging fields into it.
 *
 * The builder holds **keys**, never columns, and the preview runs through the
 * same ReportRunner a saved report does — so what somebody sees while building
 * is what they will get afterwards, and there is no second query path to keep
 * in step.
 *
 * Dimensions and measures are ordered lists rather than sets, because the order
 * is the column order and the outermost grouping. Dragging is how that order is
 * changed; `reorderDimensions` is what the drag reports back to.
 */
class ReportBuilder extends Component
{
    use AuthorizesRequests;
    use EditsConditions;

    #[Locked]
    public ?int $reportId = null;

    public string $name = '';

    public string $description = '';

    public string $source = '';

    /**
     * Dimension keys, outermost grouping first.
     *
     * @var array<int, string>
     */
    public array $dimensions = [];

    /**
     * Measure keys, in column order.
     *
     * @var array<int, string>
     */
    public array $measures = [];

    public string $grain = 'month';

    /**
     * The period the report covers when opened — relative ("last month"), so a
     * scheduled report keeps covering the right stretch.
     */
    public string $period = 'all_time';

    public string $dateField = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    /**
     * How many rows to keep, e.g. a top-20. Empty for the runner's own cap.
     */
    public string $limit = '';

    public string $sortBy = '';

    public string $sortDirection = 'desc';

    public string $chartType = 'table';

    public bool $isShared = false;

    /**
     * The filter tree. Declared here rather than inherited: the trait leaves it
     * to the using class so a list screen can put #[Url] on it and a builder
     * cannot — see EditsConditions.
     *
     * @var array<string, mixed>
     */
    public array $filters = FilterGroup::EMPTY;

    public function mount(?Report $report = null): void
    {
        if ($report?->exists) {
            $this->authorize('view', $report);

            $definition = $report->definition();

            $this->reportId = $report->id;
            $this->name = $report->name;
            $this->description = (string) $report->description;
            $this->source = $report->source;
            $this->dimensions = $definition->dimensions;
            $this->measures = $definition->measures;
            $this->grain = $definition->grain === null ? 'month' : $definition->grain->value;
            $this->sortBy = (string) $definition->sortBy;
            $this->sortDirection = $definition->sortDirection;
            $this->chartType = $report->chart_type;
            $this->isShared = $report->is_shared;
            $this->filters = $definition->filters->toArray();
            $this->period = $definition->period->value;
            $this->dateField = (string) $definition->dateField;
            $this->dateFrom = (string) $definition->dateFrom;
            $this->dateTo = (string) $definition->dateTo;
            $this->limit = $definition->limit === null ? '' : (string) $definition->limit;

            return;
        }

        $this->authorize('create', Report::class);

        // Opened on the first source the person may report on, so the screen
        // has something to show rather than an empty frame.
        $this->source = (string) array_key_first(ReportSources::optionsFor($this->currentUser()));
    }

    public function report(): ?Report
    {
        return $this->reportId === null
            ? null
            : Report::query()->whereKey($this->reportId)->first();
    }

    public function isEditing(): bool
    {
        return $this->reportId !== null;
    }

    // -- What the builder is offering ----------------------------------------

    public function reportSource(): ?ReportSource
    {
        return $this->source === '' ? null : ReportSources::find($this->source);
    }

    /**
     * @return array<string, string>
     */
    public function sourceOptions(): array
    {
        return ReportSources::optionsFor($this->currentUser());
    }

    /**
     * The fields not already in the report, which is what the tray shows.
     *
     * @return array<string, string>
     */
    public function availableDimensions(): array
    {
        $source = $this->reportSource();

        if ($source === null) {
            return [];
        }

        return array_diff_key($source->dimensionOptions(), array_flip($this->dimensions));
    }

    /**
     * @return array<string, string>
     */
    public function availableMeasures(): array
    {
        $source = $this->reportSource();

        if ($source === null) {
            return [];
        }

        return array_diff_key($source->measureOptions(), array_flip($this->measures));
    }

    /**
     * @return array<string, string>
     */
    public function chosenDimensions(): array
    {
        $source = $this->reportSource();
        $chosen = [];

        foreach ($this->dimensions as $key) {
            $dimension = $source?->dimension($key);

            if ($dimension !== null) {
                $chosen[$key] = $dimension->label;
            }
        }

        return $chosen;
    }

    /**
     * @return array<string, string>
     */
    public function chosenMeasures(): array
    {
        $source = $this->reportSource();
        $chosen = [];

        foreach ($this->measures as $key) {
            $measure = $source?->measure($key);

            if ($measure !== null) {
                $chosen[$key] = $measure->label;
            }
        }

        return $chosen;
    }

    /**
     * Whether any chosen dimension is a date, which is what makes the grain
     * control worth showing.
     */
    public function hasDateDimension(): bool
    {
        $source = $this->reportSource();

        foreach ($this->dimensions as $key) {
            if ($source?->dimension($key)?->isDate === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    public function sortOptions(): array
    {
        return [...$this->chosenDimensions(), ...$this->chosenMeasures()];
    }

    /**
     * @return array<string, string>
     */
    public function grainOptions(): array
    {
        return DateGrain::options();
    }

    /**
     * @return array<string, string>
     */
    public function periodOptions(): array
    {
        return DatePeriod::options();
    }

    /**
     * The source's dates a period can be measured on.
     *
     * @return array<string, string>
     */
    public function dateFieldOptions(): array
    {
        $options = [];

        foreach ($this->reportSource()->dimensions ?? [] as $key => $dimension) {
            if ($dimension->isDate) {
                $options[$key] = $dimension->label;
            }
        }

        return $options;
    }

    public function isCustomPeriod(): bool
    {
        return $this->period === DatePeriod::Custom->value;
    }

    /**
     * @return array<string, string>
     */
    public function chartOptions(): array
    {
        return ChartType::options();
    }

    public function chart(): ChartType
    {
        return ChartType::tryFrom($this->chartType) ?? ChartType::Table;
    }

    /**
     * Why the chosen shape will not draw, or null when it will.
     *
     * Said on the builder rather than left to the chart component, because
     * somebody choosing a pie for an ungrouped report should be told while they
     * are choosing rather than shown an empty frame afterwards.
     */
    public function chartWarning(): ?string
    {
        $chart = $this->chart();

        if ($chart->needsDimension() && $this->dimensions === []) {
            return 'A '.strtolower($chart->label()).' needs something to group by.';
        }

        if ($chart->singleMeasure() && count($this->measures) > 1) {
            return 'A '.strtolower($chart->label()).' draws one measure. The first is the one shown.';
        }

        return null;
    }

    /**
     * The filter builder's field list, which is the source's own.
     *
     * A list, not a map: the trait keys it itself, and handing it a keyed array
     * would make the builder's field dropdown print the keys.
     *
     * @return array<int, FilterField>
     */
    public function conditionFields(): array
    {
        $source = $this->reportSource();

        return $source === null ? [] : array_values($source->filters);
    }

    // -- Building ------------------------------------------------------------

    public function updatedSource(): void
    {
        // A deal's stage means nothing on a lead. Keeping the old fields would
        // silently produce a report with columns that never fill.
        $this->dimensions = [];
        $this->measures = [];
        $this->sortBy = '';
        // The date belongs to the old source; the period itself still applies.
        $this->dateField = '';
        $this->clearFilters();
        $this->forgetResult();
    }

    public function addDimension(string $key): void
    {
        if ($this->reportSource()?->dimension($key) === null) {
            return;
        }

        if (! in_array($key, $this->dimensions, true)) {
            $this->dimensions[] = $key;
        }

        $this->forgetResult();
    }

    public function removeDimension(string $key): void
    {
        $this->dimensions = array_values(array_filter($this->dimensions, fn (string $k) => $k !== $key));

        if ($this->sortBy === $key) {
            $this->sortBy = '';
        }

        $this->forgetResult();
    }

    public function addMeasure(string $key): void
    {
        if ($this->reportSource()?->measure($key) === null) {
            return;
        }

        if (! in_array($key, $this->measures, true)) {
            $this->measures[] = $key;
        }

        $this->forgetResult();
    }

    public function removeMeasure(string $key): void
    {
        $this->measures = array_values(array_filter($this->measures, fn (string $k) => $k !== $key));

        if ($this->sortBy === $key) {
            $this->sortBy = '';
        }

        $this->forgetResult();
    }

    /**
     * What a drag reports back.
     *
     * The incoming order is filtered against what is already chosen rather than
     * trusted: it arrives from the browser, and a key that is not in the report
     * has no business being added by reordering it.
     *
     * @param  array<int, string>  $order
     */
    public function reorderDimensions(array $order): void
    {
        $this->dimensions = $this->reorder($order, $this->dimensions);
        $this->forgetResult();
    }

    /**
     * @param  array<int, string>  $order
     */
    public function reorderMeasures(array $order): void
    {
        $this->measures = $this->reorder($order, $this->measures);
        $this->forgetResult();
    }

    /**
     * @param  array<int, string>  $order
     * @param  array<int, string>  $current
     * @return array<int, string>
     */
    private function reorder(array $order, array $current): array
    {
        $reordered = [];

        foreach ($order as $key) {
            // Checked against what is already chosen, not merely deduplicated:
            // the order arrives from the browser.
            if (in_array($key, $current, true) && ! in_array($key, $reordered, true)) {
                $reordered[] = $key;
            }
        }

        // Anything the browser did not mention keeps its place at the end,
        // rather than disappearing because a drag arrived mid-render.
        foreach ($current as $key) {
            if (! in_array($key, $reordered, true)) {
                $reordered[] = $key;
            }
        }

        return $reordered;
    }

    public function sortByColumn(string $key): void
    {
        if (! array_key_exists($key, $this->sortOptions())) {
            return;
        }

        if ($this->sortBy === $key) {
            $this->sortDirection = $this->sortDirection === 'desc' ? 'asc' : 'desc';
        } else {
            $this->sortBy = $key;
            $this->sortDirection = 'desc';
        }

        $this->forgetResult();
    }

    // -- Running -------------------------------------------------------------

    public function definition(): ReportDefinition
    {
        return ReportDefinition::fromArray([
            'source' => $this->source,
            'dimensions' => $this->dimensions,
            'measures' => $this->measures,
            'filters' => $this->filters,
            'grain' => $this->grain,
            'sort_by' => $this->sortBy === '' ? null : $this->sortBy,
            'sort_direction' => $this->sortDirection,
            // Carried through rather than dropped: saving a top-20 in the
            // builder used to quietly turn it into a top-1000.
            'limit' => $this->limit === '' ? null : (int) $this->limit,
            'period' => $this->period,
            'date_field' => $this->dateField === '' ? null : $this->dateField,
            'date_from' => $this->isCustomPeriod() && $this->dateFrom !== '' ? $this->dateFrom : null,
            'date_to' => $this->isCustomPeriod() && $this->dateTo !== '' ? $this->dateTo : null,
        ]);
    }

    /**
     * The preview, through the same runner a saved report uses.
     */
    #[Computed]
    public function result(): ReportResult
    {
        return app(ReportRunner::class)->run($this->definition(), $this->currentUser());
    }

    private function forgetResult(): void
    {
        unset($this->result);
    }

    /**
     * The trait's hook: any change to the condition tree drops the preview, or
     * the numbers on screen would describe the filter before last.
     */
    protected function conditionsChanged(): void
    {
        $this->forgetResult();
    }

    // -- Saving --------------------------------------------------------------

    public function save(): void
    {
        $report = $this->report();

        $report === null
            ? $this->authorize('create', Report::class)
            : $this->authorize('update', $report);

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.ReportRunner::MAX_ROWS],
            'period' => ['required', Rule::in(array_keys(DatePeriod::options()))],
            'dateFrom' => ['nullable', 'date_format:Y-m-d'],
            'dateTo' => ['nullable', 'date_format:Y-m-d'],
        ], [], ['dateFrom' => 'from date', 'dateTo' => 'to date', 'limit' => 'row limit']);

        if ($this->measures === []) {
            $this->addError('measures', 'Choose at least one thing to measure — a report with none is a list.');

            return;
        }

        if ($this->isShared && ! $this->currentUser()->can('reports.share')) {
            $this->addError('isShared', 'Sharing a report is not something you may do.');

            return;
        }

        try {
            $saved = app(SaveReportAction::class)(
                report: $report ?? new Report,
                name: $this->name,
                definition: $this->definition(),
                actor: $this->currentUser(),
                description: $this->description === '' ? null : $this->description,
                chartType: $this->chartType,
                shared: $this->isShared,
            );
        } catch (RuntimeException $exception) {
            $this->addError('source', $exception->getMessage());

            return;
        }

        session()->flash('status', $saved->name.' has been saved.');

        $this->redirectRoute('reports.show', ['report' => $saved->id], navigate: true);
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
        return view('livewire.reports.report-builder')
            ->title($this->isEditing() ? 'Edit report' : 'New report');
    }
}
