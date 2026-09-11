<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Activities\Calendar\CalendarBuilder;
use App\Domain\Activities\Calendar\CalendarEvent;
use App\Domain\Activities\Calendar\CalendarPeriod;
use App\Domain\Activities\Calendar\CalendarScale;
use App\Domain\Activities\Models\Activity;
use App\Domain\Company\Models\Company;
use App\Domain\Settings\DisplayTime;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Livewire\Calendar\ActivityCalendar;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

/**
 * Somebody who may see the whole module. Defined here rather than reused from
 * ActivityCrudTest so this file can be run on its own.
 */
function calendarAdmin(): User
{
    $role = Role::query()->create([
        'name' => 'Calendar all '.uniqid(),
        'guard_name' => Guard::getDefaultName(Role::class),
        'data_access_level' => DataAccessLevel::All->value,
    ]);

    $role->syncPermissions(PermissionResolver::models(['activities.view', 'activities.create', 'activities.update']));

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * Somebody who sees only their own work, which is the default access level.
 */
function calendarUser(): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models(['activities.view']) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

/**
 * The office is somewhere other than UTC, which is the only way to tell a
 * calendar that converts from one that happens to agree with the stored clock.
 */
function officeIn(string $timezone): void
{
    Company::current()->forceFill(['timezone' => $timezone])->save();
    Cache::flush();
}

function weekStartsOn(string $day): void
{
    settings()->set('localisation.week_starts_on', $day);
}

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow('2026-10-15 09:00:00');
    officeIn('UTC');
});

afterEach(function () {
    Carbon::setTestNow();
});

// -- The period ----------------------------------------------------------------

test('a month grid is padded out to whole weeks', function () {
    weekStartsOn('monday');

    // October 2026 starts on a Thursday and ends on a Saturday.
    $period = CalendarPeriod::for(CalendarScale::Month, Carbon::parse('2026-10-15'));

    expect($period->first->format('Y-m-d'))->toBe('2026-09-28')
        ->and($period->last->format('Y-m-d'))->toBe('2026-11-01')
        ->and($period->days())->toHaveCount(35)
        ->and($period->weeks())->toHaveCount(5);

    foreach ($period->weeks() as $week) {
        expect($week)->toHaveCount(7);
    }
});

test('the week the grid starts on is the configured one', function (string $token, string $firstCell) {
    weekStartsOn($token);

    expect(CalendarPeriod::for(CalendarScale::Month, Carbon::parse('2026-10-15'))->first->format('Y-m-d'))
        ->toBe($firstCell);
})->with([
    ['monday', '2026-09-28'],
    ['sunday', '2026-09-27'],
    ['saturday', '2026-09-26'],
]);

test('a week period runs seven days from the configured start', function () {
    weekStartsOn('sunday');

    $period = CalendarPeriod::for(CalendarScale::Week, Carbon::parse('2026-10-15'));

    expect($period->first->format('Y-m-d'))->toBe('2026-10-11')
        ->and($period->last->format('Y-m-d'))->toBe('2026-10-17')
        ->and($period->days())->toHaveCount(7);
});

test('a day period is one day', function () {
    $period = CalendarPeriod::for(CalendarScale::Day, Carbon::parse('2026-10-15'));

    expect($period->days())->toHaveCount(1)
        ->and($period->first->format('Y-m-d'))->toBe('2026-10-15');
});

test('stepping a month moves by the anchor, not by the first cell drawn', function () {
    weekStartsOn('monday');

    // The grid's first cell is 28 September; stepping from that would land in
    // October again and the month would never advance past the padding.
    $period = CalendarPeriod::for(CalendarScale::Month, Carbon::parse('2026-10-15'));

    expect($period->step(1)->anchor->format('Y-m'))->toBe('2026-11')
        ->and($period->step(-1)->anchor->format('Y-m'))->toBe('2026-09');
});

test('stepping a month off a long month does not skip one', function () {
    // 31 January + 1 month is 31 February, which overflows into March unless
    // the step says otherwise.
    $period = CalendarPeriod::for(CalendarScale::Month, Carbon::parse('2026-01-31'));

    expect($period->step(1)->anchor->format('Y-m'))->toBe('2026-02');
});

// -- Timezone safety -----------------------------------------------------------

test('the query window covers the displayed days, not the stored ones', function () {
    officeIn('Asia/Dhaka');
    weekStartsOn('monday');

    $period = CalendarPeriod::for(CalendarScale::Day, Carbon::parse('2026-10-15'));

    // Midnight to midnight in Dhaka is 18:00 the evening before to 17:59 UTC.
    expect($period->from()->format('Y-m-d H:i'))->toBe('2026-10-14 18:00')
        ->and($period->to()->format('Y-m-d H:i'))->toBe('2026-10-15 17:59');
});

test('an activity lands on the day the office is having, not the stored one', function () {
    officeIn('Asia/Dhaka');

    // 23:30 UTC on the 1st is 05:30 on the 2nd in Dhaka.
    $activity = Activity::factory()->create(['due_at' => '2026-10-01 23:30:00']);

    expect(CalendarEvent::for($activity)->dayKey())->toBe('2026-10-02');

    // And back in UTC it is the 1st, so the conversion is what moved it.
    officeIn('UTC');
    expect(CalendarEvent::for($activity->fresh())->dayKey())->toBe('2026-10-01');
});

test('an evening activity is drawn on the day the calendar asked for', function () {
    officeIn('Asia/Dhaka');

    $owner = calendarUser();
    // 20:00 in Dhaka on the 15th, which is 14:00 UTC.
    Activity::factory()->ownedBy($owner)->create([
        'subject' => 'Evening call',
        'due_at' => '2026-10-15 14:00:00',
    ]);

    $grid = app(CalendarBuilder::class)->build(
        Activity::query()->visibleTo($owner),
        CalendarPeriod::for(CalendarScale::Day, Carbon::parse('2026-10-15')),
    );

    expect($grid->day('2026-10-15')?->count())->toBe(1)
        ->and($grid->day('2026-10-15')?->events[0]->timeLabel())->toBe('20:00');
});

test('an activity stored before the window but displayed inside it is still found', function () {
    officeIn('Asia/Dhaka');

    $owner = calendarUser();
    // 2026-10-14 19:00 UTC is 01:00 on the 15th in Dhaka — outside a naive
    // 15th-to-15th query, inside the one the period builds.
    Activity::factory()->ownedBy($owner)->create([
        'subject' => 'Early start',
        'due_at' => '2026-10-14 19:00:00',
    ]);

    $grid = app(CalendarBuilder::class)->build(
        Activity::query()->visibleTo($owner),
        CalendarPeriod::for(CalendarScale::Day, Carbon::parse('2026-10-15')),
    );

    expect($grid->day('2026-10-15')?->count())->toBe(1);
});

// -- Placing events ------------------------------------------------------------

test('every day of the period gets a cell, including the empty ones', function () {
    $owner = calendarUser();

    $grid = app(CalendarBuilder::class)->build(
        Activity::query()->visibleTo($owner),
        CalendarPeriod::for(CalendarScale::Month, Carbon::parse('2026-10-15')),
    );

    expect($grid->list())->toHaveCount(35)
        ->and($grid->isEmpty())->toBeTrue();
});

test('an event is positioned by the minute, and a task gets a default block', function () {
    $owner = calendarUser();

    $meeting = Activity::factory()->ownedBy($owner)->meeting()->create([
        'due_at' => '2026-10-15 14:30:00',
        'duration_minutes' => 90,
    ]);
    $task = Activity::factory()->ownedBy($owner)->create([
        'due_at' => '2026-10-15 09:15:00',
        'duration_minutes' => null,
    ]);

    expect(CalendarEvent::for($meeting)->offsetMinutes())->toBe(14 * 60 + 30)
        ->and(CalendarEvent::for($meeting)->durationMinutes())->toBe(90)
        ->and(CalendarEvent::for($task)->offsetMinutes())->toBe(9 * 60 + 15)
        ->and(CalendarEvent::for($task)->durationMinutes())->toBe(CalendarEvent::DEFAULT_MINUTES);
});

test('a block never runs past midnight into the next day', function () {
    $activity = Activity::factory()->meeting()->create([
        'due_at' => '2026-10-15 23:30:00',
        'duration_minutes' => 120,
    ]);

    $event = CalendarEvent::for($activity);

    expect($event->endsAt->format('Y-m-d'))->toBe('2026-10-15')
        ->and($event->durationMinutes())->toBeLessThanOrEqual(30);
});

test('a day separates all-day entries from timed ones', function () {
    $owner = calendarUser();

    Activity::factory()->ownedBy($owner)->allDay()->create(['due_at' => '2026-10-15 00:00:00']);
    Activity::factory()->ownedBy($owner)->create(['due_at' => '2026-10-15 11:00:00', 'all_day' => false]);

    $grid = app(CalendarBuilder::class)->build(
        Activity::query()->visibleTo($owner),
        CalendarPeriod::for(CalendarScale::Day, Carbon::parse('2026-10-15')),
    );

    $day = $grid->day('2026-10-15');

    expect($day?->allDayEvents())->toHaveCount(1)
        ->and($day?->timedEvents())->toHaveCount(1)
        // All-day first, whatever the clock says.
        ->and($day?->events[0]->allDay)->toBeTrue();
});

test('the builder is bounded, and says when it hit the ceiling', function () {
    $owner = calendarUser();

    Activity::factory()->count(CalendarBuilder::MAX_EVENTS + 2)->ownedBy($owner)
        ->create(['due_at' => '2026-10-15 11:00:00']);

    $grid = app(CalendarBuilder::class)->build(
        Activity::query()->visibleTo($owner),
        CalendarPeriod::for(CalendarScale::Day, Carbon::parse('2026-10-15')),
    );

    expect($grid->truncated)->toBeTrue()
        ->and($grid->count())->toBe(CalendarBuilder::MAX_EVENTS);
});

// -- The screen ----------------------------------------------------------------

test('the calendar is refused without the view permission', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(ActivityCalendar::class)
        ->assertForbidden();
});

test('the calendar renders what the viewer can see, and nothing else', function () {
    $owner = calendarUser();
    $peer = calendarUser();

    Activity::factory()->ownedBy($owner)->create(['subject' => 'Mine', 'due_at' => '2026-10-15 11:00:00']);
    Activity::factory()->ownedBy($peer)->create(['subject' => 'Theirs', 'due_at' => '2026-10-15 11:00:00']);

    Livewire::actingAs($owner)
        ->test(ActivityCalendar::class)
        ->assertOk()
        ->assertSee('Mine')
        ->assertDontSee('Theirs');
});

test('the scale switches, and an unknown one is ignored', function () {
    $user = calendarAdmin();

    Livewire::actingAs($user)
        ->test(ActivityCalendar::class)
        ->assertSet('scale', CalendarScale::Month->value)
        ->call('setScale', 'week')
        ->assertSet('scale', CalendarScale::Week->value)
        ->call('setScale', 'fortnight')
        ->assertSet('scale', CalendarScale::Week->value);
});

test('moving through the calendar steps by the scale on screen', function () {
    $user = calendarAdmin();

    Livewire::actingAs($user)
        ->test(ActivityCalendar::class)
        ->set('scale', CalendarScale::Month->value)
        ->set('anchor', '2026-10-15')
        ->call('next')
        ->assertSet('anchor', '2026-11-15')
        ->call('previous')
        ->assertSet('anchor', '2026-10-15')
        ->set('scale', CalendarScale::Day->value)
        ->call('next')
        ->assertSet('anchor', '2026-10-16')
        ->call('today')
        ->assertSet('anchor', DisplayTime::now()->format('Y-m-d'));
});

test('a rubbish anchor falls back to today rather than throwing', function () {
    $user = calendarAdmin();

    Livewire::actingAs($user)
        ->test(ActivityCalendar::class, ['anchor' => 'not-a-date'])
        ->assertOk()
        ->assertSet('anchor', DisplayTime::now()->format('Y-m-d'));
});

test('the mine filter narrows the calendar to the viewer own work', function () {
    $admin = calendarAdmin();
    $peer = calendarUser();

    Activity::factory()->ownedBy($admin)->create(['subject' => 'My call', 'due_at' => '2026-10-15 11:00:00']);
    Activity::factory()->ownedBy($peer)->create(['subject' => 'Their call', 'due_at' => '2026-10-15 11:00:00']);

    Livewire::actingAs($admin)
        ->test(ActivityCalendar::class)
        ->assertSee('My call')
        ->assertSee('Their call')
        ->set('mineOnly', true)
        ->assertSee('My call')
        ->assertDontSee('Their call');
});

test('the type filter only accepts a real type', function () {
    $user = calendarAdmin();

    Activity::factory()->ownedBy($user)->meeting()->create(['subject' => 'Kickoff', 'due_at' => '2026-10-15 11:00:00']);
    Activity::factory()->ownedBy($user)->create(['subject' => 'Paperwork', 'due_at' => '2026-10-15 11:00:00']);

    Livewire::actingAs($user)
        ->test(ActivityCalendar::class)
        ->set('type', 'meeting')
        ->assertSee('Kickoff')
        ->assertDontSee('Paperwork')
        ->set('type', 'banquet')
        ->assertSee('Kickoff')
        ->assertSee('Paperwork');
});

test('the calendar route resolves for somebody who may see activities', function () {
    $this->actingAs(calendarAdmin())->get(route('calendar'))->assertOk();

    $this->actingAs(User::factory()->create())->get(route('calendar'))->assertForbidden();
});

test('a guest is sent to sign in', function () {
    $this->get(route('calendar'))->assertRedirect(route('login'));
});
