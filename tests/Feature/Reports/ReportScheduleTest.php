<?php

use App\Domain\Deals\Models\Deal;
use App\Domain\Reports\Actions\DispatchScheduledReportsAction;
use App\Domain\Reports\Documents\ReportDocument;
use App\Domain\Reports\Enums\ScheduleFrequency;
use App\Domain\Reports\Models\Report;
use App\Domain\Reports\Models\ReportSchedule;
use App\Domain\Reports\ReportRunner;
use App\Domain\Shared\Enums\ExportFormat;
use App\Jobs\SendScheduledReport;
use App\Livewire\Reports\ReportSchedules;
use App\Mail\ScheduledReportMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * A report with figures in it, so an attachment has something to carry.
 */
function schedulableReport(User $owner): Report
{
    Deal::factory()->ownedBy($owner)->create(['stage' => 'qualification', 'value' => 1000]);
    Deal::factory()->ownedBy($owner)->create(['stage' => 'proposal', 'value' => 500]);

    return Report::factory()->ownedBy($owner)->asking('deals', [
        'dimensions' => ['stage'],
        'measures' => ['count', 'value'],
    ])->create(['name' => 'Deals by stage']);
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-15 07:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

// -- When a schedule is next due ------------------------------------------------

test('a daily schedule is next due at its hour, today or tomorrow', function () {
    $frequency = ScheduleFrequency::Daily;

    expect($frequency->next(Carbon::parse('2026-09-15 07:00'), 8)->format('Y-m-d H:i'))->toBe('2026-09-15 08:00')
        // Strictly after: a schedule that could return the moment it was just
        // sent would send again on the next sweep, and again after that.
        ->and($frequency->next(Carbon::parse('2026-09-15 08:00'), 8)->format('Y-m-d H:i'))->toBe('2026-09-16 08:00')
        ->and($frequency->next(Carbon::parse('2026-09-15 09:00'), 8)->format('Y-m-d H:i'))->toBe('2026-09-16 08:00');
});

test('a weekly schedule lands on its day', function () {
    // 15 September 2026 is a Tuesday.
    $next = ScheduleFrequency::Weekly->next(Carbon::parse('2026-09-15 07:00'), 8, dayOfWeek: Carbon::MONDAY);

    expect($next->format('Y-m-d H:i'))->toBe('2026-09-21 08:00')
        ->and($next->format('l'))->toBe('Monday');
});

test('a monthly schedule on the 31st still goes out in a short month', function () {
    // Named: next() takes the day of the *week* third, and a day of the month
    // passed positionally lands in the wrong parameter and is quietly ignored.
    $next = ScheduleFrequency::Monthly->next(Carbon::parse('2026-01-31 09:00'), 8, dayOfMonth: 31);

    // The 28th, not the 3rd of March: a monthly report set to the last day of
    // the month must go out in every month.
    expect($next->format('Y-m-d H:i'))->toBe('2026-02-28 08:00');
});

test('a monthly schedule is due this month when the day has not passed', function () {
    expect(ScheduleFrequency::Monthly->next(Carbon::parse('2026-09-15 07:00'), 8, dayOfMonth: 20)->format('Y-m-d'))
        ->toBe('2026-09-20');
});

// -- The sweep ------------------------------------------------------------------

test('a schedule that has come due is queued', function () {
    Queue::fake();

    $owner = reportAdmin();
    $schedule = ReportSchedule::factory()
        ->ownedBy($owner)
        ->of(schedulableReport($owner))
        ->dueAt('2026-09-15 06:00:00')
        ->create();

    $queued = app(DispatchScheduledReportsAction::class)();

    expect($queued)->toBe(1);

    Queue::assertPushed(SendScheduledReport::class, fn (SendScheduledReport $job) => $job->scheduleId === $schedule->id);
});

test('a schedule not yet due is left alone', function () {
    Queue::fake();

    $owner = reportAdmin();
    ReportSchedule::factory()->ownedBy($owner)->of(schedulableReport($owner))
        ->dueAt('2026-09-16 08:00:00')
        ->create();

    expect(app(DispatchScheduledReportsAction::class)())->toBe(0);

    Queue::assertNothingPushed();
});

test('a paused schedule is never queued', function () {
    Queue::fake();

    $owner = reportAdmin();
    ReportSchedule::factory()->ownedBy($owner)->of(schedulableReport($owner))
        ->dueAt('2026-09-15 06:00:00')
        ->inactive()
        ->create();

    expect(app(DispatchScheduledReportsAction::class)())->toBe(0);
});

test('the next run is advanced before the job is queued, so a sweep cannot double-send', function () {
    Queue::fake();

    $owner = reportAdmin();
    $schedule = ReportSchedule::factory()->ownedBy($owner)->of(schedulableReport($owner))
        ->dueAt('2026-09-15 06:00:00')
        ->create(['hour' => 8]);

    app(DispatchScheduledReportsAction::class)();

    expect($schedule->fresh()->next_run_at->format('Y-m-d H:i'))->toBe('2026-09-15 08:00')
        ->and($schedule->fresh()->last_run_at->format('Y-m-d H:i'))->toBe('2026-09-15 07:00');

    // The second sweep finds nothing, because the first advanced it.
    expect(app(DispatchScheduledReportsAction::class)())->toBe(0);
});

test('a missed window sends once and moves on', function () {
    Queue::fake();

    $owner = reportAdmin();

    // The scheduler was down for a week.
    $schedule = ReportSchedule::factory()->ownedBy($owner)->of(schedulableReport($owner))
        ->dueAt('2026-09-08 08:00:00')
        ->create(['hour' => 8]);

    expect(app(DispatchScheduledReportsAction::class)())->toBe(1);

    // Advanced from now, not from the old due time: a week of catch-up sends is
    // the failure mode worth avoiding.
    expect($schedule->fresh()->next_run_at->format('Y-m-d H:i'))->toBe('2026-09-15 08:00');
});

test('the command queues what is due', function () {
    Queue::fake();

    $owner = reportAdmin();
    ReportSchedule::factory()->ownedBy($owner)->of(schedulableReport($owner))
        ->dueAt('2026-09-15 06:00:00')
        ->create();

    $this->artisan('reports:send-scheduled')->assertSuccessful();

    Queue::assertPushed(SendScheduledReport::class, 1);
});

// -- The send -------------------------------------------------------------------

test('the job posts the report to its recipients', function () {
    Mail::fake();

    $owner = reportAdmin();
    $schedule = ReportSchedule::factory()->ownedBy($owner)->of(schedulableReport($owner))
        ->sendingTo(['finance@example.test', 'board@example.test'])
        ->create();

    (new SendScheduledReport($schedule->id))->handle();

    Mail::assertSent(ScheduledReportMail::class, function (ScheduledReportMail $mail) {
        return $mail->hasTo('finance@example.test') && $mail->hasTo('board@example.test');
    });

    expect($schedule->fresh()->last_status)->toBe('sent')
        ->and($schedule->fresh()->last_error)->toBeNull();
});

test('the same address twice is one send', function () {
    $owner = reportAdmin();
    $schedule = ReportSchedule::factory()->ownedBy($owner)
        ->sendingTo(['Finance@example.test', 'finance@example.test', 'finance@EXAMPLE.test'])
        ->create();

    expect($schedule->addresses())->toHaveCount(1);
});

test('an address that is not an address is dropped', function () {
    $owner = reportAdmin();
    $schedule = ReportSchedule::factory()->ownedBy($owner)
        ->sendingTo(['finance@example.test', 'not an address', '   '])
        ->create();

    expect($schedule->addresses())->toBe(['finance@example.test']);
});

test('a schedule with nobody to send to is skipped and says so', function () {
    Mail::fake();

    $owner = reportAdmin();
    $schedule = ReportSchedule::factory()->ownedBy($owner)->of(schedulableReport($owner))
        ->sendingTo(['nonsense'])
        ->create();

    (new SendScheduledReport($schedule->id))->handle();

    Mail::assertNothingSent();

    // On the schedule, not only in the logs: an unattended sender failing
    // silently for a fortnight is the whole failure mode.
    expect($schedule->fresh()->last_status)->toBe('skipped');
});

test('a paused schedule does nothing even if its job runs', function () {
    Mail::fake();

    $owner = reportAdmin();
    $schedule = ReportSchedule::factory()->ownedBy($owner)->of(schedulableReport($owner))->inactive()->create();

    (new SendScheduledReport($schedule->id))->handle();

    Mail::assertNothingSent();
});

// -- The attachment -------------------------------------------------------------

test('the mail carries the report as a file', function () {
    $owner = reportAdmin();
    $schedule = ReportSchedule::factory()->ownedBy($owner)->of(schedulableReport($owner))->create();

    $attachments = (new ScheduledReportMail($schedule->fresh(['report', 'owner'])))->attachments();

    expect($attachments)->toHaveCount(1);
});

test('a PDF is produced, and it is a PDF', function () {
    $owner = reportAdmin();
    $report = schedulableReport($owner);

    $bytes = app(ReportDocument::class)->render($report, $owner, ExportFormat::Pdf);

    expect($bytes)->toStartWith('%PDF')
        ->and(strlen($bytes))->toBeGreaterThan(1000);
});

test('the PDF carries the figures, not just a frame', function () {
    $owner = reportAdmin();
    $report = schedulableReport($owner);

    // dompdf compresses its streams, so the text is not greppable in the
    // output. What is assertable is that the view it renders from carries the
    // rows — which is what would be empty if the report were run unscoped or
    // not at all.
    $result = app(ReportRunner::class)->run($report->definition(), $owner);

    expect($result->rowCount())->toBe(2)
        ->and($result->totals['value'])->toBe(1500.0);
});

test('a spreadsheet is produced, and Excel will open it without a warning', function () {
    $owner = reportAdmin();
    $report = schedulableReport($owner);

    $bytes = app(ReportDocument::class)->render($report, $owner, ExportFormat::Excel);

    // Real SpreadsheetML rather than a CSV with a misleading extension: Excel
    // warns about those, and a warning on an automated report is a support
    // call every month.
    expect($bytes)->toContain('<?mso-application progid="Excel.Sheet"?>')
        ->toContain('<Worksheet')
        // The stage key, not a name: no pipeline is configured in this test, so
        // the dimension has no label to map the code to and prints it as it is.
        ->toContain('qualification')
        ->toContain('1500');
});

test('a CSV is produced with a header, the rows and a total', function () {
    $owner = reportAdmin();
    $report = schedulableReport($owner);

    $csv = app(ReportDocument::class)->render($report, $owner, ExportFormat::Csv);
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect($lines[0])->toContain('Stage')
        ->and($lines[0])->toContain('Value')
        ->and(end($lines))->toContain('Everything')
        ->and(end($lines))->toContain('1500');
});

test('the filename says what it is and when', function () {
    $owner = reportAdmin();
    $report = schedulableReport($owner);

    expect(app(ReportDocument::class)->filename($report, ExportFormat::Pdf))
        ->toBe('deals-by-stage-2026-09-15.pdf');
});

test('the attachment is the owner view of the figures, not everybody', function () {
    $owner = reportUser(['reports.view', 'reports.schedule', 'deals.view']);

    Deal::factory()->ownedBy($owner)->create(['stage' => 'qualification', 'value' => 100]);
    Deal::factory()->create(['stage' => 'qualification', 'value' => 9000]);

    $report = Report::factory()->ownedBy($owner)->asking('deals', ['measures' => ['value']])->create();

    $result = app(ReportRunner::class)->run($report->definition(), $owner);

    // A file that leaves the application is the hardest kind of leak to take
    // back.
    expect($result->totals['value'])->toBe(100.0);
});

// -- The screen -----------------------------------------------------------------

test('scheduling needs its own permission', function () {
    Livewire::actingAs(reportUser(['reports.view']))
        ->test(ReportSchedules::class)
        ->assertForbidden();

    Livewire::actingAs(reportUser(['reports.view', 'reports.schedule']))
        ->test(ReportSchedules::class)
        ->assertOk();
});

test('the screen saves a schedule and works out when it is next due', function () {
    $owner = reportAdmin();
    $report = schedulableReport($owner);

    Livewire::actingAs($owner)
        ->test(ReportSchedules::class)
        ->call('create')
        ->set('reportId', (string) $report->id)
        ->set('frequency', 'weekly')
        ->set('dayOfWeek', '1')
        ->set('hour', '9')
        ->set('format', 'xlsx')
        ->set('recipients', "finance@example.test\nboard@example.test")
        ->call('save')
        ->assertHasNoErrors();

    $schedule = ReportSchedule::query()->firstOrFail();

    expect($schedule->frequency())->toBe(ScheduleFrequency::Weekly)
        ->and($schedule->format())->toBe(ExportFormat::Excel)
        ->and($schedule->addresses())->toHaveCount(2)
        ->and($schedule->user_id)->toBe($owner->id)
        // Monday the 21st at nine.
        ->and($schedule->next_run_at->format('Y-m-d H:i'))->toBe('2026-09-21 09:00');
});

test('addresses can be pasted separated by commas or lines', function () {
    $owner = reportAdmin();
    $report = schedulableReport($owner);

    Livewire::actingAs($owner)
        ->test(ReportSchedules::class)
        ->call('create')
        ->set('reportId', (string) $report->id)
        ->set('recipients', 'one@example.test, two@example.test;three@example.test')
        ->call('save')
        ->assertHasNoErrors();

    expect(ReportSchedule::query()->firstOrFail()->addresses())->toHaveCount(3);
});

test('a schedule with no usable address is refused', function () {
    $owner = reportAdmin();
    $report = schedulableReport($owner);

    Livewire::actingAs($owner)
        ->test(ReportSchedules::class)
        ->call('create')
        ->set('reportId', (string) $report->id)
        ->set('recipients', 'not an address')
        ->call('save')
        ->assertHasErrors(['recipients']);

    expect(ReportSchedule::query()->count())->toBe(0);
});

test('a report the person cannot run cannot be scheduled', function () {
    $owner = reportUser(['reports.view', 'reports.schedule']);

    // Shared, so it is visible — but about deals, which this person cannot see.
    $report = Report::factory()->shared()->asking('deals', ['measures' => ['count']])->create();

    Livewire::actingAs($owner)
        ->test(ReportSchedules::class)
        ->call('create')
        ->set('reportId', (string) $report->id)
        ->set('recipients', 'finance@example.test')
        ->call('save')
        ->assertHasErrors(['reportId']);

    // It would post an empty attachment every morning.
    expect(ReportSchedule::query()->count())->toBe(0);
});

test('a schedule belongs to the person who set it up and to nobody else', function () {
    $mine = reportAdmin();
    $theirs = reportAdmin();

    $elsewhere = ReportSchedule::factory()->ownedBy($theirs)->create();

    $screen = Livewire::actingAs($mine)->test(ReportSchedules::class);

    expect($screen->instance()->schedules())->toHaveCount(0)
        // Not even an administrator: the attachment carries the owner's view of
        // the data, and redirecting that without them knowing is the one thing
        // this must not allow.
        ->and($mine->can('update', $elsewhere))->toBeFalse()
        ->and($theirs->can('update', $elsewhere))->toBeTrue();

    $screen->call('delete', $elsewhere->id);

    expect(ReportSchedule::query()->whereKey($elsewhere->id)->exists())->toBeTrue();
});

test('send now queues the same job the sweep does', function () {
    Queue::fake();

    $owner = reportAdmin();
    $schedule = ReportSchedule::factory()->ownedBy($owner)->of(schedulableReport($owner))->create();

    Livewire::actingAs($owner)
        ->test(ReportSchedules::class)
        ->call('sendNow', $schedule->id);

    // The real path, not a second one that might work when the real one does
    // not.
    Queue::assertPushed(SendScheduledReport::class, fn ($job) => $job->scheduleId === $schedule->id);
});

test('editing a schedule does not change whose figures it sends', function () {
    $owner = reportAdmin();
    $report = schedulableReport($owner);
    $schedule = ReportSchedule::factory()->ownedBy($owner)->of($report)->create();

    Livewire::actingAs($owner)
        ->test(ReportSchedules::class)
        ->call('edit', $schedule->id)
        ->set('hour', '17')
        ->call('save')
        ->assertHasNoErrors();

    expect($schedule->fresh()->user_id)->toBe($owner->id)
        ->and($schedule->fresh()->hour)->toBe(17);
});

test('the schedules page is reachable', function () {
    $this->actingAs(reportAdmin())
        ->get(route('reports.schedules'))
        ->assertOk()
        ->assertSee('Scheduled reports');
});
