<?php

namespace App\Livewire\Reports;

use App\Domain\Reports\Dimension;
use App\Domain\Reports\Enums\ChartType;
use App\Domain\Reports\Enums\DatePeriod;
use App\Domain\Reports\Models\Report;
use App\Domain\Reports\ReportDefinition;
use App\Domain\Reports\ReportRecords;
use App\Domain\Reports\ReportResult;
use App\Domain\Reports\ReportRow;
use App\Domain\Reports\ReportRunner;
use App\Domain\Settings\DisplayTime;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One saved report, run.
 *
 * Run as the **viewer**, never as the author: two people opening the same
 * shared report see different numbers because they can see different records,
 * and that is the correct behaviour rather than a bug to paper over.
 *
 * The period and the drilled row live in the URL, so "salesperson performance,
 * last quarter, Karim's deals" is a link somebody can send. Neither changes the
 * saved report: a reader choosing another period is reading, not editing.
 *
 * @property-read ReportResult $result
 * @property-read ReportRecords|null $records
 * @property-read array<string, array<int, string>> $links
 */
class ReportShow extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $reportId;

    /**
     * A DatePeriod value, or empty for the period the report was saved with.
     */
    #[Url(as: 'period', except: '')]
    public string $period = '';

    #[Url(as: 'date', except: '')]
    public string $dateField = '';

    #[Url(as: 'from', except: '')]
    public string $from = '';

    #[Url(as: 'to', except: '')]
    public string $to = '';

    /**
     * The row being looked into: dimension key => that row's raw value. Only
     * keys the report groups by are honoured, by the runner.
     *
     * @var array<string, string>
     */
    #[Url(as: 'drill', except: [])]
    public array $drill = [];

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

    /**
     * The saved definition, over the period the reader picked if they picked one.
     */
    public function definition(): ReportDefinition
    {
        $saved = $this->report()->definition();
        $period = DatePeriod::tryFrom($this->period);

        if ($period === null) {
            return $this->dateField === '' ? $saved : $saved->withPeriod($saved->period, $this->dateField, $saved->dateFrom, $saved->dateTo);
        }

        return $saved->withPeriod(
            $period,
            $this->dateField === '' ? null : $this->dateField,
            $period === DatePeriod::Custom ? $this->from : null,
            $period === DatePeriod::Custom ? $this->to : null,
        );
    }

    #[Computed]
    public function result(): ReportResult
    {
        return app(ReportRunner::class)->run($this->definition(), $this->currentUser());
    }

    #[Computed]
    public function records(): ?ReportRecords
    {
        if ($this->drill === []) {
            return null;
        }

        return app(ReportRunner::class)->records($this->definition(), $this->currentUser(), $this->drillKeys());
    }

    /**
     * @param  array<string, mixed>  $keys
     */
    public function drillInto(array $keys): void
    {
        $this->drill = $this->sanitiseDrill($keys);

        unset($this->records);
    }

    public function clearDrill(): void
    {
        $this->drill = [];

        unset($this->records);
    }

    public function updatedPeriod(): void
    {
        if ($this->period !== DatePeriod::Custom->value) {
            $this->from = '';
            $this->to = '';
        }

        $this->forgetResults();
    }

    public function updatedDateField(): void
    {
        $this->forgetResults();
    }

    public function updatedFrom(): void
    {
        $this->forgetResults();
    }

    public function updatedTo(): void
    {
        $this->forgetResults();
    }

    /**
     * The period currently in force, as a DatePeriod.
     */
    public function activePeriod(): DatePeriod
    {
        return $this->definition()->period;
    }

    /**
     * The date dimensions a period can be measured on, keyed for a dropdown.
     *
     * @return array<string, string>
     */
    public function dateFieldOptions(): array
    {
        $source = $this->report()->reportSource();

        if ($source === null) {
            return [];
        }

        $options = [];

        foreach ($source->dimensions as $key => $dimension) {
            if ($dimension->isDate) {
                $options[$key] = $dimension->label;
            }
        }

        return $options;
    }

    /**
     * The key of the date the period is measured on.
     */
    public function activeDateField(): ?string
    {
        $source = $this->report()->reportSource();

        return $source === null ? null : app(ReportRunner::class)->periodDimension($this->definition(), $source)?->key;
    }

    /**
     * The period spelled out as dates, for the heading — "1 Jul – 30 Sep 2026".
     */
    public function periodSummary(): ?string
    {
        $definition = $this->definition();
        $days = $definition->period->days(Carbon::now(DisplayTime::timezone()), $definition->dateFrom, $definition->dateTo);

        if ($days === null) {
            return null;
        }

        [$from, $to] = $days;

        return match (true) {
            $from !== null && $to !== null => $from->format('j M Y').' – '.$to->format('j M Y'),
            $from !== null => 'From '.$from->format('j M Y'),
            $to !== null => 'Up to '.$to->format('j M Y'),
            default => null,
        };
    }

    /**
     * Where each linkable group of the current result goes, and only for records
     * this reader may open — a name they can see in an aggregate is not a
     * record they can necessarily view.
     *
     * @return array<string, array<int, string>> Dimension key => record id => URL.
     */
    #[Computed]
    public function links(): array
    {
        $result = $this->result;
        $links = [];

        foreach ($result->dimensions as $key => $dimension) {
            if (! $this->isLinkable($dimension)) {
                continue;
            }

            $ids = [];

            foreach ($result->rows as $row) {
                $id = $row->id($key);

                if ($id !== null) {
                    $ids[$id] = $id;
                }
            }

            if ($ids === []) {
                continue;
            }

            /** @var class-string<Model> $model */
            $model = (string) $dimension->recordModel;
            $visible = $model::query()->scopes(['visibleTo' => [$this->currentUser()]])->whereKey(array_values($ids))->pluck('id');

            foreach ($visible as $id) {
                $links[$key][(int) $id] = route((string) $dimension->recordRoute, (int) $id);
            }
        }

        return $links;
    }

    /**
     * The drilled row described in words, for the panel heading.
     */
    public function drillSummary(): string
    {
        $source = $this->report()->reportSource();
        $parts = [];

        foreach ($this->drillKeys() as $key => $value) {
            $dimension = $source?->dimension($key);

            if ($dimension === null || ! in_array($key, $this->definition()->dimensions, true)) {
                continue;
            }

            $parts[] = $dimension->label.': '.$this->drillLabel($key, $value);
        }

        return $parts === [] ? 'Every record' : implode(' · ', $parts);
    }

    public function chart(): ChartType
    {
        return ChartType::tryFrom($this->report()->chart_type) ?? ChartType::Table;
    }

    public function canEdit(): bool
    {
        return auth()->user()?->can('update', $this->report()) === true;
    }

    public function render(): View
    {
        $report = $this->report();

        return view('livewire.reports.report-show', [
            'report' => $report,
            'periods' => DatePeriod::options(),
        ])->title($report->name);
    }

    /**
     * @return array<string, string>
     */
    private function drillKeys(): array
    {
        return $this->sanitiseDrill($this->drill);
    }

    /**
     * Strings only, and only keys the report groups by: the URL is typed by
     * anybody, and the runner checks again rather than trusting this.
     *
     * @param  array<array-key, mixed>  $keys
     * @return array<string, string>
     */
    private function sanitiseDrill(array $keys): array
    {
        $dimensions = $this->definition()->dimensions;
        $clean = [];

        foreach ($keys as $key => $value) {
            if (is_string($key) && in_array($key, $dimensions, true) && (is_string($value) || is_int($value))) {
                $clean[$key] = (string) $value;
            }
        }

        return $clean;
    }

    /**
     * A drilled value as the row printed it.
     */
    private function drillLabel(string $key, string $value): string
    {
        foreach ($this->result->rows as $row) {
            if (($row->keys[$key] ?? null) === $value) {
                return (string) $row->group($key);
            }
        }

        return $value === ReportRow::NONE ? '(none)' : $value;
    }

    private function isLinkable(Dimension $dimension): bool
    {
        return $dimension->isRecord()
            && $dimension->recordRoute !== null
            && $dimension->recordModel !== null
            && Route::has($dimension->recordRoute)
            && method_exists($dimension->recordModel, 'scopeVisibleTo');
    }

    private function forgetResults(): void
    {
        unset($this->result, $this->records, $this->links);
    }

    private function currentUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
