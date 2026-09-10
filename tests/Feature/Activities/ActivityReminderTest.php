<?php

use App\Domain\Activities\Actions\CompleteActivityAction;
use App\Domain\Activities\Actions\ReopenActivityAction;
use App\Domain\Activities\Actions\SendActivityRemindersAction;
use App\Domain\Activities\Enums\RecurrenceFrequency;
use App\Domain\Activities\Models\Activity;
use App\Jobs\SendNotification;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

function sweepReminders(?Carbon $now = null): int
{
    return app(SendActivityRemindersAction::class)($now);
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-01 09:00:00');
});

// -- The lead time -------------------------------------------------------------

test('a reminder is queued once its lead time has arrived', function () {
    Queue::fake();

    $owner = User::factory()->create();
    Activity::factory()->ownedBy($owner)->remindingAfter(30)
        ->create(['due_at' => '2026-10-01 09:20']);

    // Due in twenty minutes, reminder set for thirty minutes before, so it is
    // already overdue to be sent.
    expect(sweepReminders())->toBe(1);

    // The engine queues the delivery; nothing is sent inside the sweep.
    Queue::assertPushed(SendNotification::class);
});

test('a reminder whose lead time has not arrived waits', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $activity = Activity::factory()->ownedBy($owner)->remindingAfter(30)
        ->create(['due_at' => '2026-10-01 11:00']);

    expect(sweepReminders())->toBe(0)
        ->and($activity->fresh()->reminder_sent_at)->toBeNull();

    Queue::assertNothingPushed();
});

test('each activity is measured against its own lead time', function () {
    Queue::fake();

    $owner = User::factory()->create();
    // Both due at 10:00, but only one of them wants two hours' notice.
    $soon = Activity::factory()->ownedBy($owner)->remindingAfter(120)
        ->create(['due_at' => '2026-10-01 10:00']);
    $later = Activity::factory()->ownedBy($owner)->remindingAfter(15)
        ->create(['due_at' => '2026-10-01 10:00']);

    expect(sweepReminders())->toBe(1)
        ->and($soon->fresh()->reminder_sent_at)->not->toBeNull()
        ->and($later->fresh()->reminder_sent_at)->toBeNull();
});

test('an activity with no reminder configured is never swept up', function () {
    Queue::fake();

    Activity::factory()->create(['due_at' => '2026-10-01 09:05']);

    expect(sweepReminders())->toBe(0);
    Queue::assertNothingPushed();
});

// -- Once, and only once -------------------------------------------------------

test('a reminder is not sent twice', function () {
    Queue::fake();

    $owner = User::factory()->create();
    Activity::factory()->ownedBy($owner)->remindingAfter(30)
        ->create(['due_at' => '2026-10-01 09:20']);

    expect(sweepReminders())->toBe(1)
        // The sweep runs every minute; without the stamp this would fire sixty
        // times an hour.
        ->and(sweepReminders())->toBe(0);
});

test('the stamp is written even when nobody could be told', function () {
    Queue::fake();

    $activity = Activity::factory()->remindingAfter(30)
        ->create(['due_at' => '2026-10-01 09:20']);
    // The owner column cannot be null, so a removed owner is what this looks
    // like to the sweep: users are soft-deleted, and the relation then resolves
    // to nothing.
    User::query()->whereKey($activity->owner_id)->delete();

    expect(sweepReminders())->toBe(0)
        ->and($activity->fresh()->reminder_sent_at)->not->toBeNull();
});

test('a completed activity is not reminded about', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $activity = Activity::factory()->ownedBy($owner)->remindingAfter(30)
        ->create(['due_at' => '2026-10-01 09:20']);

    app(CompleteActivityAction::class)($activity);

    expect(sweepReminders())->toBe(0);
    Queue::assertNothingPushed();
});

test('a cancelled activity is not reminded about', function () {
    Queue::fake();

    $owner = User::factory()->create();
    Activity::factory()->ownedBy($owner)->cancelled()->remindingAfter(30)
        ->create(['due_at' => '2026-10-01 09:20']);

    expect(sweepReminders())->toBe(0);
});

test('a reminder older than a day is written off rather than sent', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $activity = Activity::factory()->ownedBy($owner)->remindingAfter(30)
        ->create(['due_at' => '2026-09-20 09:00']);

    // If the scheduler was down for a week, "your call was due last Tuesday"
    // arriving as a reminder is noise; the activity is on the overdue list,
    // which is where it belongs by then.
    expect(sweepReminders())->toBe(0)
        ->and($activity->fresh()->reminder_sent_at)->toBeNull();

    Queue::assertNothingPushed();
});

test('the stale cut-off is a day, not a moment', function () {
    Queue::fake();

    $owner = User::factory()->create();
    Activity::factory()->ownedBy($owner)->remindingAfter(30)
        ->create(['due_at' => '2026-09-30 22:00']);

    expect(SendActivityRemindersAction::STALE_AFTER_HOURS)->toBe(24)
        // Eleven hours late is still worth telling somebody about.
        ->and(sweepReminders())->toBe(1);
});

// -- Reopening -----------------------------------------------------------------

test('a reopened activity can be reminded about again', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $activity = Activity::factory()->ownedBy($owner)->remindingAfter(30)
        ->create(['due_at' => '2026-10-01 09:20']);

    sweepReminders();
    // Refreshed because the sweep stamped the row behind this instance: an
    // action fills and saves, and save() writes only what is dirty, so a stale
    // instance would have nothing to clear.
    $activity->refresh();

    app(CompleteActivityAction::class)($activity);
    app(ReopenActivityAction::class)($activity);

    expect(sweepReminders())->toBe(1);
});

// -- What the person receives ---------------------------------------------------

test('the reminder reaches the owner, in-app', function () {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    Activity::factory()->ownedBy($owner)->remindingAfter(30)
        ->create(['due_at' => '2026-10-01 09:20', 'subject' => 'Call Dana back']);

    sweepReminders();

    expect($owner->unreadNotifications()->count())->toBe(1);
});

test('the reminder has no actor, so the owner is not treated as its cause', function () {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    Activity::factory()->ownedBy($owner)->remindingAfter(0)
        ->create(['due_at' => '2026-10-01 09:00']);

    // Nobody performed this; the clock did. Passing the owner as the actor
    // would make the engine drop it as their own action and the reminder would
    // never arrive.
    sweepReminders();

    expect($owner->unreadNotifications()->count())->toBe(1);
});

// -- The command ---------------------------------------------------------------

test('the scheduled command runs the sweep', function () {
    Queue::fake();

    $owner = User::factory()->create();
    Activity::factory()->ownedBy($owner)->remindingAfter(30)
        ->create(['due_at' => '2026-10-01 09:20']);

    $this->artisan('activities:send-reminders')
        ->expectsOutputToContain('1 reminder queued')
        ->assertSuccessful();
});

test('the recurrence command runs the sweep', function () {
    Activity::factory()
        ->repeating(RecurrenceFrequency::Weekly)
        ->create(['due_at' => '2026-10-01 09:00']);

    $this->artisan('activities:generate-recurrences')
        ->expectsOutputToContain('occurrences created')
        ->assertSuccessful();
});

test('both sweeps are scheduled', function () {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($event) => $event->command ?? '')
        ->implode(' ');

    expect($commands)->toContain('activities:send-reminders')
        ->and($commands)->toContain('activities:generate-recurrences');
});
