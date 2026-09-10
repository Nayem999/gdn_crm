<?php

use App\Domain\Activities\Actions\CompleteActivityAction;
use App\Domain\Activities\Actions\DeleteActivityAction;
use App\Domain\Activities\Actions\GenerateRecurringActivitiesAction;
use App\Domain\Activities\Actions\UpdateActivityAction;
use App\Domain\Activities\Enums\ActivityStatus;
use App\Domain\Activities\Enums\RecurrenceFrequency;
use App\Domain\Activities\Models\Activity;
use Illuminate\Support\Carbon;

/**
 * The dates a series produces after its first occurrence.
 *
 * @return array<int, string>
 */
function occurrenceDates(Activity $master): array
{
    return $master->occurrences()->get()
        ->map(fn (Activity $occurrence) => $occurrence->due_at->format('Y-m-d H:i'))
        ->all();
}

function generateOccurrences(?Activity $master = null, ?Carbon $horizon = null): int
{
    return app(GenerateRecurringActivitiesAction::class)($master, $horizon);
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-01 09:00:00');
});

// -- The window ----------------------------------------------------------------

test('a weekly series materialises occurrences up to the horizon', function () {
    $master = Activity::factory()
        ->repeating(RecurrenceFrequency::Weekly)
        ->create(['due_at' => '2026-10-01 09:00']);

    generateOccurrences($master, Carbon::parse('2026-10-29 09:00'));

    // The master is the first occurrence; these are the ones alongside it.
    expect(occurrenceDates($master))->toBe([
        '2026-10-08 09:00',
        '2026-10-15 09:00',
        '2026-10-22 09:00',
        '2026-10-29 09:00',
    ]);
});

test('nothing beyond the horizon is created', function () {
    $master = Activity::factory()
        ->repeating(RecurrenceFrequency::Daily)
        ->create(['due_at' => '2026-10-01 09:00']);

    generateOccurrences($master, Carbon::parse('2026-10-04 09:00'));

    expect($master->occurrences()->count())->toBe(3);
});

test('the default horizon is three months, not forever', function () {
    $master = Activity::factory()
        ->repeating(RecurrenceFrequency::Weekly)
        ->create(['due_at' => '2026-10-01 09:00']);

    generateOccurrences($master);

    $last = $master->occurrences()->get()->last();

    expect($last)->not->toBeNull()
        ->and($last->due_at->lessThanOrEqualTo(now()->addDays(GenerateRecurringActivitiesAction::HORIZON_DAYS)))
        ->toBeTrue()
        // A series with no end date has infinitely many occurrences, so the
        // window is what bounds them.
        ->and($master->occurrences()->count())->toBeLessThan(20);
});

// -- Idempotence ---------------------------------------------------------------

test('running the sweep twice creates nothing the second time', function () {
    $master = Activity::factory()
        ->repeating(RecurrenceFrequency::Weekly)
        ->create(['due_at' => '2026-10-01 09:00']);

    $first = generateOccurrences($master, Carbon::parse('2026-11-01 09:00'));
    $second = generateOccurrences($master, Carbon::parse('2026-11-01 09:00'));

    expect($first)->toBeGreaterThan(0)
        ->and($second)->toBe(0);
});

test('an occurrence somebody removed stays removed', function () {
    $master = Activity::factory()
        ->repeating(RecurrenceFrequency::Weekly)
        ->create(['due_at' => '2026-10-01 09:00']);

    generateOccurrences($master, Carbon::parse('2026-10-29 09:00'));

    $unwanted = $master->occurrences()->get()->firstOrFail();
    app(DeleteActivityAction::class)($unwanted);

    // Without withTrashed in the duplicate check the nightly sweep would put
    // every deleted appointment straight back.
    expect(generateOccurrences($master, Carbon::parse('2026-10-29 09:00')))->toBe(0)
        ->and(occurrenceDates($master))->not->toContain('2026-10-08 09:00');
});

test('the sweep picks up every series without being told which', function () {
    Activity::factory()->repeating(RecurrenceFrequency::Weekly)->create(['due_at' => '2026-10-01 09:00']);
    Activity::factory()->repeating(RecurrenceFrequency::Monthly)->create(['due_at' => '2026-10-02 09:00']);
    Activity::factory()->create(['due_at' => '2026-10-03 09:00']);

    expect(generateOccurrences())->toBeGreaterThan(0)
        ->and(Activity::query()->whereNotNull('recurrence_parent_id')->count())->toBeGreaterThan(0);
});

// -- Where a series stops ------------------------------------------------------

test('a count includes the first occurrence', function () {
    // "Repeat 4 times" means four appointments in total, which is how every
    // calendar application states it.
    $master = Activity::factory()
        ->repeating(RecurrenceFrequency::Weekly, count: 4)
        ->create(['due_at' => '2026-10-01 09:00']);

    generateOccurrences($master, Carbon::parse('2027-01-01 09:00'));

    expect($master->occurrences()->count())->toBe(3)
        ->and(occurrenceDates($master))->toBe([
            '2026-10-08 09:00',
            '2026-10-15 09:00',
            '2026-10-22 09:00',
        ]);
});

test('an end date stops the series on that day, inclusive', function () {
    $master = Activity::factory()
        ->repeating(RecurrenceFrequency::Weekly, until: '2026-10-15')
        ->create(['due_at' => '2026-10-01 09:00']);

    generateOccurrences($master, Carbon::parse('2027-01-01 09:00'));

    // 15 October is included even though the appointment is at 09:00 and the
    // end date carries no time of its own.
    expect(occurrenceDates($master))->toBe([
        '2026-10-08 09:00',
        '2026-10-15 09:00',
    ]);
});

test('an interval is honoured', function () {
    $master = Activity::factory()
        ->repeating(RecurrenceFrequency::Weekly, interval: 2)
        ->create(['due_at' => '2026-10-01 09:00']);

    generateOccurrences($master, Carbon::parse('2026-11-01 09:00'));

    expect(occurrenceDates($master))->toBe([
        '2026-10-15 09:00',
        '2026-10-29 09:00',
    ]);
});

// -- The month-end trap --------------------------------------------------------

test('a monthly series that starts on the 31st does not walk off the end of the month', function () {
    // addMonth() on 31 January lands on 3 March. Stepping one at a time from
    // there walks the series further out every month and never comes back.
    $master = Activity::factory()
        ->repeating(RecurrenceFrequency::Monthly)
        ->create(['due_at' => '2027-01-31 09:00']);

    generateOccurrences($master, Carbon::parse('2027-06-01 09:00'));

    expect(occurrenceDates($master))->toBe([
        '2027-02-28 09:00',
        '2027-03-31 09:00',
        '2027-04-30 09:00',
        '2027-05-31 09:00',
    ]);
});

test('every step is measured from the series start, so nothing drifts', function () {
    $master = Activity::factory()
        ->repeating(RecurrenceFrequency::Monthly)
        ->create(['due_at' => '2027-01-30 09:00']);

    generateOccurrences($master, Carbon::parse('2027-04-01 09:00'));

    // If February clamped to the 28th and March were then counted from there,
    // March would be the 28th rather than the 30th.
    expect(occurrenceDates($master))->toBe([
        '2027-02-28 09:00',
        '2027-03-30 09:00',
    ]);
});

test('a yearly series handles the leap day', function () {
    $master = Activity::factory()
        ->repeating(RecurrenceFrequency::Yearly)
        ->create(['due_at' => '2028-02-29 09:00']);

    generateOccurrences($master, Carbon::parse('2030-03-01 09:00'));

    expect(occurrenceDates($master))->toBe([
        '2029-02-28 09:00',
        '2030-02-28 09:00',
    ]);
});

// -- What an occurrence is -----------------------------------------------------

test('an occurrence carries no rule of its own', function () {
    $master = Activity::factory()
        ->repeating(RecurrenceFrequency::Weekly)
        ->create(['due_at' => '2026-10-01 09:00']);

    generateOccurrences($master, Carbon::parse('2026-10-15 09:00'));

    $occurrence = $master->occurrences()->get()->firstOrFail();

    // A generated row carrying a rule would generate rows of its own, and the
    // second generation would do it again.
    expect($occurrence->recurrence())->toBeNull()
        ->and($occurrence->isSeriesMaster())->toBeFalse()
        ->and($occurrence->isOccurrence())->toBeTrue()
        ->and(generateOccurrences($occurrence, Carbon::parse('2027-01-01 09:00')))->toBe(0);
});

test('an occurrence inherits everything else from its series', function () {
    $master = Activity::factory()
        ->meeting()
        ->repeating(RecurrenceFrequency::Weekly)
        ->remindingAfter(30)
        ->create([
            'due_at' => '2026-10-01 09:00',
            'subject' => 'Weekly check-in',
            'location' => 'Their office',
        ]);

    generateOccurrences($master, Carbon::parse('2026-10-08 09:00'));

    $occurrence = $master->occurrences()->get()->firstOrFail();

    expect($occurrence->subject)->toBe('Weekly check-in')
        ->and($occurrence->type())->toBe($master->type())
        ->and($occurrence->location)->toBe('Their office')
        ->and($occurrence->owner_id)->toBe($master->owner_id)
        ->and($occurrence->reminder_minutes_before)->toBe(30)
        ->and($occurrence->status())->toBe(ActivityStatus::Open);
});

test('each occurrence is completed on its own', function () {
    $master = Activity::factory()
        ->repeating(RecurrenceFrequency::Weekly)
        ->create(['due_at' => '2026-10-01 09:00']);

    generateOccurrences($master, Carbon::parse('2026-10-15 09:00'));

    $first = $master->occurrences()->get()->firstOrFail();
    app(CompleteActivityAction::class)($first);

    expect($first->fresh()->isCompleted())->toBeTrue()
        ->and($master->fresh()->isOpen())->toBeTrue()
        ->and($master->occurrences()->where('status', ActivityStatus::Open->value)->count())->toBe(1);
});

test('completing the first appointment does not stop the series', function () {
    $master = Activity::factory()
        ->repeating(RecurrenceFrequency::Weekly)
        ->create(['due_at' => '2026-10-01 09:00']);

    app(CompleteActivityAction::class)($master);

    expect(generateOccurrences(null, Carbon::parse('2026-10-15 09:00')))->toBeGreaterThan(0);
});

test('a cancelled series stops producing', function () {
    $master = Activity::factory()
        ->repeating(RecurrenceFrequency::Weekly)
        ->cancelled()
        ->create(['due_at' => '2026-10-01 09:00']);

    expect(generateOccurrences(null, Carbon::parse('2026-11-01 09:00')))->toBe(0)
        ->and($master->occurrences()->count())->toBe(0);
});

// -- Changing a series ---------------------------------------------------------

test('changing the rule regenerates the future and leaves the past alone', function () {
    $owner = activityAdmin();
    $master = Activity::factory()
        ->ownedBy($owner)
        ->repeating(RecurrenceFrequency::Weekly)
        ->create(['due_at' => '2026-09-03 09:00', 'subject' => 'Weekly check-in']);

    generateOccurrences($master, Carbon::parse('2026-11-01 09:00'));

    $past = $master->occurrences()->where('due_at', '<', now())->pluck('id')->all();

    expect($past)->not->toBeEmpty();

    app(UpdateActivityAction::class)(
        $master,
        activityData([
            'subject' => 'Weekly check-in',
            'due_at' => '2026-09-03 09:00',
            'recurrence_frequency' => 'monthly',
            'owner_id' => (string) $owner->id,
        ]),
        $owner
    );

    $master->refresh();

    // What has already happened is a record of what took place, and a changed
    // rule does not reach back and rewrite it.
    foreach ($past as $id) {
        expect(Activity::query()->whereKey($id)->exists())->toBeTrue();
    }

    $future = $master->occurrences()->where('due_at', '>=', now())->get();

    expect($future)->not->toBeEmpty();

    foreach ($future as $occurrence) {
        // Monthly now, so nothing in the future is a week apart.
        expect($occurrence->due_at->day)->toBe(3);
    }
});

test('changing the rule and changing it back brings the appointments back', function () {
    $owner = activityAdmin();
    $master = Activity::factory()
        ->ownedBy($owner)
        ->repeating(RecurrenceFrequency::Weekly)
        ->create(['due_at' => '2026-10-01 09:00', 'subject' => 'Weekly check-in']);

    generateOccurrences($master, Carbon::parse('2026-10-29 09:00'));

    $weekly = occurrenceDates($master);

    foreach (['monthly', 'weekly'] as $frequency) {
        app(UpdateActivityAction::class)(
            $master,
            activityData([
                'subject' => 'Weekly check-in',
                'due_at' => '2026-10-01 09:00',
                'recurrence_frequency' => $frequency,
                'owner_id' => (string) $owner->id,
            ]),
            $owner
        );
        $master->refresh();
    }

    generateOccurrences($master, Carbon::parse('2026-10-29 09:00'));

    // A rule change force-deletes the appointments it invalidates rather than
    // soft-deleting them, so they are not mistaken for ones a person removed
    // on purpose and tombstoned for good. Every original date is back — there
    // are more of them now only because the update ran the generator over the
    // full window.
    foreach ($weekly as $date) {
        expect(occurrenceDates($master))->toContain($date);
    }
});

test('an occurrence somebody already completed survives a rule change', function () {
    $owner = activityAdmin();
    $master = Activity::factory()
        ->ownedBy($owner)
        ->repeating(RecurrenceFrequency::Weekly)
        ->create(['due_at' => '2026-10-01 09:00', 'subject' => 'Weekly check-in']);

    generateOccurrences($master, Carbon::parse('2026-11-01 09:00'));

    $done = $master->occurrences()->get()->firstOrFail();
    app(CompleteActivityAction::class)($done);

    app(UpdateActivityAction::class)(
        $master,
        activityData([
            'subject' => 'Weekly check-in',
            'due_at' => '2026-10-01 09:00',
            'recurrence_frequency' => 'monthly',
            'owner_id' => (string) $owner->id,
        ]),
        $owner
    );

    expect(Activity::query()->whereKey($done->id)->exists())->toBeTrue();
});

test('editing an occurrence edits that appointment, not the series', function () {
    $owner = activityAdmin();
    $master = Activity::factory()
        ->ownedBy($owner)
        ->repeating(RecurrenceFrequency::Weekly)
        ->create(['due_at' => '2026-10-01 09:00', 'subject' => 'Weekly check-in']);

    generateOccurrences($master, Carbon::parse('2026-10-15 09:00'));

    $occurrence = $master->occurrences()->get()->firstOrFail();

    app(UpdateActivityAction::class)(
        $occurrence,
        activityData([
            'subject' => 'Moved to Thursday',
            'due_at' => '2026-10-09 09:00',
            'recurrence_frequency' => 'daily',
            'owner_id' => (string) $owner->id,
        ]),
        $owner
    );

    $occurrence->refresh();

    expect($occurrence->subject)->toBe('Moved to Thursday')
        // A rule submitted for an occurrence is ignored: it would turn one
        // appointment into a series inside a series.
        ->and($occurrence->recurrence_frequency)->toBeNull()
        ->and($occurrence->occurrences()->count())->toBe(0)
        ->and($master->fresh()->subject)->toBe('Weekly check-in');
});

test('removing a series takes its open appointments with it', function () {
    $master = Activity::factory()
        ->repeating(RecurrenceFrequency::Weekly)
        ->create(['due_at' => '2026-10-01 09:00']);

    generateOccurrences($master, Carbon::parse('2026-11-01 09:00'));

    $done = $master->occurrences()->get()->firstOrFail();
    app(CompleteActivityAction::class)($done);

    app(DeleteActivityAction::class)($master);

    // The foreign key cascades on a hard delete only, so a soft-deleted master
    // would otherwise leave its appointments on everybody's calendar.
    expect(Activity::query()->where('recurrence_parent_id', $master->id)->count())->toBe(1)
        ->and(Activity::query()->whereKey($done->id)->exists())->toBeTrue();
});

test('force-deleting a series takes every appointment, completed or not', function () {
    $master = Activity::factory()
        ->repeating(RecurrenceFrequency::Weekly)
        ->create(['due_at' => '2026-10-01 09:00']);

    generateOccurrences($master, Carbon::parse('2026-11-01 09:00'));

    $master->forceDelete();

    expect(Activity::query()->withTrashed()->where('recurrence_parent_id', $master->id)->count())->toBe(0);
});

// -- The rule reads back -------------------------------------------------------

test('a rule says what it does', function (string $frequency, int $interval, ?int $count, ?string $until, string $expected) {
    $master = Activity::factory()
        ->repeating(RecurrenceFrequency::from($frequency), interval: $interval, count: $count, until: $until)
        ->create(['due_at' => '2026-10-01 09:00']);

    expect($master->recurrence()?->label())->toBe($expected);
})->with([
    'weekly' => ['weekly', 1, null, null, 'Every week'],
    'fortnightly' => ['weekly', 2, null, null, 'Every 2 weeks'],
    'counted' => ['daily', 1, 5, null, 'Every day, 5 times'],
    'ended' => ['monthly', 1, null, '2027-03-31', 'Every month until 31 Mar 2027'],
]);
