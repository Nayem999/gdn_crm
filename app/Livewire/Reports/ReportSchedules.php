<?php

namespace App\Livewire\Reports;

use App\Domain\Reports\Actions\SaveReportScheduleAction;
use App\Domain\Reports\Enums\ScheduleFrequency;
use App\Domain\Reports\Models\Report;
use App\Domain\Reports\Models\ReportSchedule;
use App\Domain\Shared\Enums\ExportFormat;
use App\Jobs\SendScheduledReport;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * The reports that post themselves.
 *
 * Only your own: a schedule sends **your** view of the data to addresses you
 * chose, so somebody else editing it would redirect your figures without your
 * knowing. That is the one thing this screen must not allow, and it is why the
 * policy has no administrator branch.
 */
#[Title('Scheduled reports')]
class ReportSchedules extends Component
{
    use AuthorizesRequests;

    public bool $editing = false;

    public ?int $editingId = null;

    public string $reportId = '';

    public string $frequency = 'daily';

    public string $format = 'pdf';

    public string $hour = '8';

    public string $dayOfWeek = '1';

    public string $dayOfMonth = '1';

    /**
     * Addresses as typed: one per line, which is how people paste them.
     */
    public string $recipients = '';

    public bool $isActive = true;

    public function mount(): void
    {
        $this->authorize('viewAny', ReportSchedule::class);
    }

    /**
     * @return Collection<int, ReportSchedule>
     */
    #[Computed]
    public function schedules(): Collection
    {
        return ReportSchedule::query()
            ->forUser($this->currentUser())
            ->with('report')
            ->orderBy('next_run_at')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public function reportOptions(): array
    {
        $user = $this->currentUser();
        $options = [];

        foreach (Report::query()->visibleTo($user)->orderBy('name')->get() as $report) {
            // A report that cannot run for this person would post an empty
            // attachment every morning.
            if ($report->runnableBy($user)) {
                $options[$report->id] = $report->name;
            }
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function frequencyOptions(): array
    {
        return ScheduleFrequency::options();
    }

    /**
     * @return array<string, string>
     */
    public function formatOptions(): array
    {
        $options = [];

        foreach (ExportFormat::cases() as $format) {
            $options[$format->value] = $format->label();
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public function hourOptions(): array
    {
        $options = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $options[$hour] = str_pad((string) $hour, 2, '0', STR_PAD_LEFT).':00';
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public function dayOfWeekOptions(): array
    {
        return [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
    }

    public function chosenFrequency(): ScheduleFrequency
    {
        return ScheduleFrequency::tryFrom($this->frequency) ?? ScheduleFrequency::Daily;
    }

    // -- Editing -------------------------------------------------------------

    public function create(): void
    {
        $this->authorize('create', ReportSchedule::class);

        $this->resetForm();
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $schedule = $this->schedule($id);

        if ($schedule === null) {
            return;
        }

        $this->authorize('update', $schedule);

        $this->editingId = $schedule->id;
        $this->reportId = (string) $schedule->report_id;
        $this->frequency = $schedule->frequency()->value;
        $this->format = $schedule->format()->value;
        $this->hour = (string) $schedule->hour;
        $this->dayOfWeek = (string) ($schedule->day_of_week ?? 1);
        $this->dayOfMonth = (string) ($schedule->day_of_month ?? 1);
        $this->recipients = implode("\n", (array) $schedule->recipients);
        $this->isActive = $schedule->is_active;
        $this->editing = true;
    }

    public function save(): void
    {
        $schedule = $this->editingId === null ? new ReportSchedule : $this->schedule($this->editingId);

        if ($schedule === null) {
            return;
        }

        $schedule->exists
            ? $this->authorize('update', $schedule)
            : $this->authorize('create', ReportSchedule::class);

        $this->validate([
            'reportId' => ['required', 'integer', 'exists:reports,id'],
            'recipients' => ['required', 'string', 'max:2000'],
            'hour' => ['required', 'integer', 'min:0', 'max:23'],
        ], attributes: ['reportId' => 'report']);

        $user = $this->currentUser();
        $report = Report::query()->visibleTo($user)->whereKey((int) $this->reportId)->first();

        // Checked again after validation: `exists` proves the report is real,
        // never that this person may read it.
        if ($report === null || ! $report->runnableBy($user)) {
            $this->addError('reportId', 'That is not a report you can schedule.');

            return;
        }

        try {
            app(SaveReportScheduleAction::class)(
                schedule: $schedule,
                report: $report,
                owner: $user,
                frequency: $this->chosenFrequency(),
                format: ExportFormat::tryFrom($this->format) ?? ExportFormat::Pdf,
                recipients: $this->addressLines(),
                hour: (int) $this->hour,
                dayOfWeek: (int) $this->dayOfWeek,
                dayOfMonth: (int) $this->dayOfMonth,
                active: $this->isActive,
            );
        } catch (RuntimeException $exception) {
            $this->addError('recipients', $exception->getMessage());

            return;
        }

        unset($this->schedules);

        $this->editing = false;
        $this->resetForm();

        $this->dispatch('notify', type: 'success', message: 'The schedule has been saved.');
    }

    public function delete(int $id): void
    {
        $schedule = $this->schedule($id);

        if ($schedule === null) {
            return;
        }

        $this->authorize('delete', $schedule);

        $schedule->delete();

        unset($this->schedules);

        $this->dispatch('notify', type: 'success', message: 'The schedule has been removed.');
    }

    /**
     * Send it now, without waiting for its hour.
     *
     * Queued exactly as the sweep does, so "send me a test" exercises the real
     * path rather than a second one that might work when the real one does not.
     */
    public function sendNow(int $id): void
    {
        $schedule = $this->schedule($id);

        if ($schedule === null) {
            return;
        }

        $this->authorize('update', $schedule);

        SendScheduledReport::dispatch($schedule->id);

        $this->dispatch('notify', type: 'success', message: 'On its way.');
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->resetForm();
    }

    /**
     * The addresses as typed: one per line or comma-separated, both of which
     * are how people paste them.
     *
     * @return array<int, string>
     */
    private function addressLines(): array
    {
        $parts = preg_split('/[\r\n,;]+/', $this->recipients) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $line) => $line !== ''));
    }

    /**
     * Scoped to this person before the policy is asked, so somebody else's id
     * is not even loaded.
     */
    private function schedule(int $id): ?ReportSchedule
    {
        return ReportSchedule::query()->forUser($this->currentUser())->whereKey($id)->first();
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->reportId = '';
        $this->frequency = 'daily';
        $this->format = 'pdf';
        $this->hour = '8';
        $this->dayOfWeek = '1';
        $this->dayOfMonth = '1';
        $this->recipients = '';
        $this->isActive = true;
        $this->resetErrorBag();
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
        return view('livewire.reports.report-schedules');
    }
}
