<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Activities\Actions\BookMeetingAction;
use App\Domain\Activities\Enums\ActivityStatus;
use App\Domain\Activities\Enums\ActivityType;
use App\Domain\Activities\Models\Activity;
use App\Domain\Activities\Scheduling\AvailabilityFinder;
use App\Domain\Activities\Scheduling\WorkingHours;
use App\Domain\Company\Models\Company;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Livewire\Activities\BookMeeting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

/**
 * @param  array<int, string>  $permissions
 */
function booker(array $permissions = ['activities.view', 'activities.create', 'contacts.view']): User
{
    $role = Role::query()->create([
        'name' => 'Booking '.uniqid(),
        'guard_name' => Guard::getDefaultName(Role::class),
        'data_access_level' => DataAccessLevel::All->value,
    ]);

    $role->syncPermissions(PermissionResolver::models($permissions));

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

function bookableContact(): Contact
{
    return Contact::factory()->create();
}

beforeEach(function () {
    Cache::flush();
    // A Wednesday, so the default Monday-to-Friday week is open.
    Carbon::setTestNow('2026-10-14 07:00:00');
    Company::current()->forceFill(['timezone' => 'UTC'])->save();
    Cache::flush();
});

afterEach(function () {
    Carbon::setTestNow();
});

// -- The working week ----------------------------------------------------------

test('the working week comes from settings', function (string $preset, string $day, bool $open) {
    settings()->set('scheduling.working_week', $preset);

    expect(WorkingHours::isWorkingDay(Carbon::parse($day)))->toBe($open);
})->with([
    // 2026-10-17 is a Saturday, 2026-10-18 a Sunday, 2026-10-19 a Monday.
    ['mon_fri', '2026-10-17', false],
    ['mon_fri', '2026-10-19', true],
    ['sun_thu', '2026-10-18', true],
    ['sun_thu', '2026-10-16', false],
    ['all', '2026-10-17', true],
]);

test('the working day is read on the office clock, not the stored one', function () {
    Company::current()->forceFill(['timezone' => 'Asia/Dhaka'])->save();
    Cache::flush();
    settings()->set('scheduling.day_starts_at', '09:00');

    $opens = WorkingHours::opensOn(Carbon::parse('2026-10-14'));

    expect($opens->format('H:i'))->toBe('09:00')
        ->and($opens->timezone->getName())->toBe('Asia/Dhaka')
        // Nine in Dhaka is three in the morning, stored.
        ->and($opens->copy()->utc()->format('H:i'))->toBe('03:00');
});

// -- Availability --------------------------------------------------------------

test('slots cover the working day at the configured interval', function () {
    settings()->set('scheduling.day_starts_at', '09:00');
    settings()->set('scheduling.day_ends_at', '12:00');
    settings()->set('scheduling.slot_minutes', '30');

    $owner = booker();
    $slots = app(AvailabilityFinder::class)->slotsFor($owner->id, Carbon::parse('2026-10-14'), 30);

    expect($slots)->toHaveCount(6)
        ->and($slots[0]->label())->toBe('09:00')
        ->and(end($slots)->label())->toBe('11:30');
});

test('a slot outside the working week is not offered at all', function () {
    settings()->set('scheduling.working_week', 'mon_fri');

    $owner = booker();

    expect(app(AvailabilityFinder::class)->slotsFor($owner->id, Carbon::parse('2026-10-17'), 30))->toBe([]);
});

test('a meeting longer than the working day leaves nothing to offer', function () {
    settings()->set('scheduling.day_starts_at', '09:00');
    settings()->set('scheduling.day_ends_at', '10:00');

    $owner = booker();

    expect(app(AvailabilityFinder::class)->slotsFor($owner->id, Carbon::parse('2026-10-14'), 120))->toBe([]);
});

test('a booked appointment takes its slot out of circulation', function () {
    settings()->set('scheduling.day_starts_at', '09:00');
    settings()->set('scheduling.day_ends_at', '12:00');

    $owner = booker();
    Activity::factory()->ownedBy($owner)->meeting()->create([
        'subject' => 'Standup',
        'due_at' => '2026-10-14 10:00:00',
        'duration_minutes' => 30,
    ]);

    $slots = app(AvailabilityFinder::class)->slotsFor($owner->id, Carbon::parse('2026-10-14'), 30);
    $taken = array_values(array_filter($slots, fn ($slot) => ! $slot->isFree()));

    expect($taken)->toHaveCount(1)
        ->and($taken[0]->label())->toBe('10:00')
        ->and($taken[0]->conflictLabel())->toContain('Standup');
});

test('a long meeting needs every slot it spans, not just the first', function () {
    settings()->set('scheduling.day_starts_at', '09:00');
    settings()->set('scheduling.day_ends_at', '12:00');
    settings()->set('scheduling.slot_minutes', '30');

    $owner = booker();
    // 10:00–10:30 is busy. A 60-minute meeting at 09:30 would run into it.
    Activity::factory()->ownedBy($owner)->meeting()->create([
        'due_at' => '2026-10-14 10:00:00',
        'duration_minutes' => 30,
    ]);

    $slots = app(AvailabilityFinder::class)->slotsFor($owner->id, Carbon::parse('2026-10-14'), 60);
    $free = array_map(fn ($slot) => $slot->label(), array_filter($slots, fn ($slot) => $slot->isFree()));

    expect(array_values($free))->not->toContain('09:30')
        ->and(array_values($free))->toContain('09:00')
        ->and(array_values($free))->toContain('10:30');
});

test('back to back meetings do not count as a clash', function () {
    $owner = booker();
    Activity::factory()->ownedBy($owner)->meeting()->create([
        'due_at' => '2026-10-14 10:00:00',
        'duration_minutes' => 30,
    ]);

    // 10:30 starts exactly where the other one ends.
    expect(app(AvailabilityFinder::class)->isFree($owner->id, Carbon::parse('2026-10-14 10:30:00'), 30))->toBeTrue()
        ->and(app(AvailabilityFinder::class)->isFree($owner->id, Carbon::parse('2026-10-14 10:15:00'), 30))->toBeFalse();
});

test('an all-day entry does not block the day, and a cancelled one gives its slot back', function () {
    $owner = booker();

    Activity::factory()->ownedBy($owner)->allDay('2026-10-14 00:00:00')->create();
    Activity::factory()->ownedBy($owner)->meeting()->cancelled()->create([
        'due_at' => '2026-10-14 10:00:00',
        'duration_minutes' => 30,
    ]);

    expect(app(AvailabilityFinder::class)->isFree($owner->id, Carbon::parse('2026-10-14 10:00:00'), 30))->toBeTrue();
});

test('somebody else being busy does not block this diary', function () {
    $owner = booker();
    $other = booker();

    Activity::factory()->ownedBy($other)->meeting()->create([
        'due_at' => '2026-10-14 10:00:00',
        'duration_minutes' => 30,
    ]);

    expect(app(AvailabilityFinder::class)->isFree($owner->id, Carbon::parse('2026-10-14 10:00:00'), 30))->toBeTrue();
});

test('an appointment running over from last night still blocks this morning', function () {
    settings()->set('scheduling.day_starts_at', '00:00');
    settings()->set('scheduling.day_ends_at', '12:00');

    $owner = booker();
    Activity::factory()->ownedBy($owner)->meeting()->create([
        'due_at' => '2026-10-13 23:30:00',
        'duration_minutes' => 120,
    ]);

    // The block is clamped to its own day for drawing, but the clash test reads
    // the real duration — so 23:30 + 2h still covers 00:30 the next morning.
    expect(app(AvailabilityFinder::class)->isFree($owner->id, Carbon::parse('2026-10-14 00:30:00'), 30))->toBeFalse();
});

// -- The action ----------------------------------------------------------------

test('booking creates a meeting about the record', function () {
    $actor = booker();
    $contact = bookableContact();

    $activity = app(BookMeetingAction::class)(
        slot: '2026-10-14 10:00',
        minutes: 45,
        ownerId: $actor->id,
        subject: 'Renewal discussion',
        actor: $actor,
        relatedModule: 'contacts',
        relatedId: $contact->id,
        location: 'Video call',
    );

    expect($activity->type()->value)->toBe(ActivityType::Meeting->value)
        ->and($activity->subject)->toBe('Renewal discussion')
        ->and($activity->duration_minutes)->toBe(45)
        ->and($activity->location)->toBe('Video call')
        ->and($activity->all_day)->toBeFalse()
        ->and($activity->status)->toBe(ActivityStatus::Open->value)
        ->and($activity->owner_id)->toBe($actor->id)
        ->and($activity->related_id)->toBe($contact->id)
        ->and($activity->due_at->format('Y-m-d H:i'))->toBe('2026-10-14 10:00');
});

test('the slot is read on the office clock', function () {
    Company::current()->forceFill(['timezone' => 'Asia/Dhaka'])->save();
    Cache::flush();
    settings()->set('scheduling.day_starts_at', '09:00');
    settings()->set('scheduling.day_ends_at', '18:00');

    $actor = booker();

    $activity = app(BookMeetingAction::class)(
        slot: '2026-10-14 15:00',
        minutes: 30,
        ownerId: $actor->id,
        subject: 'Afternoon call',
        actor: $actor,
    );

    // Three in the afternoon in Dhaka is nine in the morning, stored.
    expect($activity->due_at->format('Y-m-d H:i'))->toBe('2026-10-14 09:00');
});

test('booking refuses a slot that has gone', function () {
    $actor = booker();

    Activity::factory()->ownedBy($actor)->meeting()->create([
        'due_at' => '2026-10-14 10:00:00',
        'duration_minutes' => 60,
    ]);

    expect(fn () => app(BookMeetingAction::class)(
        slot: '2026-10-14 10:30',
        minutes: 30,
        ownerId: $actor->id,
        subject: 'Clashing',
        actor: $actor,
    ))->toThrow(RuntimeException::class, 'just been taken');
});

test('booking refuses a day outside the working week', function () {
    settings()->set('scheduling.working_week', 'mon_fri');

    $actor = booker();

    expect(fn () => app(BookMeetingAction::class)(
        slot: '2026-10-17 10:00',
        minutes: 30,
        ownerId: $actor->id,
        subject: 'Saturday',
        actor: $actor,
    ))->toThrow(RuntimeException::class, 'outside the working week');
});

test('booking refuses a time that has already passed', function () {
    $actor = booker();

    expect(fn () => app(BookMeetingAction::class)(
        slot: '2026-10-13 10:00',
        minutes: 30,
        ownerId: $actor->id,
        subject: 'Yesterday',
        actor: $actor,
    ))->toThrow(RuntimeException::class, 'already passed');
});

test('booking into somebody else diary tells them', function () {
    $actor = booker(['activities.view', 'activities.create', 'activities.assign', 'contacts.view']);
    $colleague = booker();

    app(BookMeetingAction::class)(
        slot: '2026-10-14 10:00',
        minutes: 30,
        ownerId: $colleague->id,
        subject: 'Handover',
        actor: $actor,
    );

    expect(NotificationLog::query()->where('event', 'activity.assigned')->exists())->toBeTrue();
});

// -- The screen ----------------------------------------------------------------

test('the booking screen is refused to somebody who may not create activities', function () {
    $contact = bookableContact();
    $viewer = booker(['activities.view', 'contacts.view']);

    Livewire::actingAs($viewer)
        ->test(BookMeeting::class, ['module' => 'contacts', 'record' => $contact->id])
        ->assertOk()
        // The component renders — it is part of the record page — but offers
        // nothing to press.
        ->assertDontSee('Book a meeting');
});

test('a record outside the person access level is refused', function () {
    // Somebody else's contact, and a booker who only sees their own records.
    $contact = Contact::factory()->create(['owner_id' => User::factory()->create()->id]);

    $stranger = User::factory()->create();
    foreach (PermissionResolver::models(['activities.view', 'activities.create', 'contacts.view']) as $permission) {
        $stranger->givePermissionTo($permission);
    }

    Livewire::actingAs($stranger->fresh())
        ->test(BookMeeting::class, ['module' => 'contacts', 'record' => $contact->id])
        ->assertNotFound();
});

test('a module outside the registry is refused', function () {
    Livewire::actingAs(booker())
        ->test(BookMeeting::class, ['module' => 'invoices', 'record' => 1])
        ->assertNotFound();
});

test('the screen books the chosen slot', function () {
    settings()->set('scheduling.day_starts_at', '09:00');
    settings()->set('scheduling.day_ends_at', '17:00');

    $actor = booker();
    $contact = bookableContact();

    Livewire::actingAs($actor)
        ->test(BookMeeting::class, ['module' => 'contacts', 'record' => $contact->id])
        ->set('date', '2026-10-15')
        ->set('subject', 'Renewal discussion')
        ->call('chooseSlot', '2026-10-15 10:00')
        ->assertSet('slot', '2026-10-15 10:00')
        ->call('book')
        ->assertHasNoErrors();

    $activity = Activity::query()->where('subject', 'Renewal discussion')->firstOrFail();

    expect($activity->due_at->format('Y-m-d H:i'))->toBe('2026-10-15 10:00')
        ->and($activity->related_id)->toBe($contact->id);
});

test('a slot the screen never offered is not accepted', function () {
    settings()->set('scheduling.day_starts_at', '09:00');
    settings()->set('scheduling.day_ends_at', '17:00');

    $actor = booker();
    $contact = bookableContact();

    Livewire::actingAs($actor)
        ->test(BookMeeting::class, ['module' => 'contacts', 'record' => $contact->id])
        ->set('date', '2026-10-15')
        // Three in the morning is not in the working day.
        ->call('chooseSlot', '2026-10-15 03:00')
        ->assertSet('slot', '');
});

test('a slot somebody has taken cannot be chosen', function () {
    settings()->set('scheduling.day_starts_at', '09:00');
    settings()->set('scheduling.day_ends_at', '17:00');

    $actor = booker();
    $contact = bookableContact();

    Activity::factory()->ownedBy($actor)->meeting()->create([
        'due_at' => '2026-10-15 10:00:00',
        'duration_minutes' => 30,
    ]);

    Livewire::actingAs($actor)
        ->test(BookMeeting::class, ['module' => 'contacts', 'record' => $contact->id])
        ->set('date', '2026-10-15')
        ->call('chooseSlot', '2026-10-15 10:00')
        ->assertSet('slot', '');
});

test('a slot taken between drawing the list and pressing the button is reported', function () {
    settings()->set('scheduling.day_starts_at', '09:00');
    settings()->set('scheduling.day_ends_at', '17:00');

    $actor = booker();
    $contact = bookableContact();

    $screen = Livewire::actingAs($actor)
        ->test(BookMeeting::class, ['module' => 'contacts', 'record' => $contact->id])
        ->set('date', '2026-10-15')
        ->set('subject', 'Renewal discussion')
        ->call('chooseSlot', '2026-10-15 10:00');

    // Somebody else gets there first.
    Activity::factory()->ownedBy($actor)->meeting()->create([
        'due_at' => '2026-10-15 10:00:00',
        'duration_minutes' => 30,
    ]);

    $screen->call('book')
        ->assertHasErrors('slot')
        ->assertSet('slot', '');
});

test('booking tells the timeline on the same page to re-read', function () {
    settings()->set('scheduling.day_starts_at', '09:00');
    settings()->set('scheduling.day_ends_at', '17:00');

    $actor = booker();
    $contact = bookableContact();

    Livewire::actingAs($actor)
        ->test(BookMeeting::class, ['module' => 'contacts', 'record' => $contact->id])
        ->set('date', '2026-10-15')
        ->set('subject', 'Renewal discussion')
        ->call('chooseSlot', '2026-10-15 10:00')
        ->call('book')
        ->assertHasNoErrors()
        // A named event, not Livewire's magic $refresh: the record page holds
        // several components and this says which one is meant.
        ->assertDispatched('timeline-changed');
});

test('the form insists on a subject and a slot', function () {
    $actor = booker();
    $contact = bookableContact();

    Livewire::actingAs($actor)
        ->test(BookMeeting::class, ['module' => 'contacts', 'record' => $contact->id])
        ->call('book')
        ->assertHasErrors(['subject', 'slot']);
});

test('somebody without the assign permission books their own diary whatever they send', function () {
    settings()->set('scheduling.day_starts_at', '09:00');
    settings()->set('scheduling.day_ends_at', '17:00');

    $actor = booker();
    $colleague = booker();
    $contact = bookableContact();

    Livewire::actingAs($actor)
        ->test(BookMeeting::class, ['module' => 'contacts', 'record' => $contact->id])
        ->set('date', '2026-10-15')
        ->set('ownerId', $colleague->id)
        ->set('subject', 'Renewal discussion')
        ->call('chooseSlot', '2026-10-15 10:00')
        ->call('book')
        ->assertHasNoErrors();

    expect(Activity::query()->where('subject', 'Renewal discussion')->value('owner_id'))->toBe($actor->id);
});

test('the picker opens on the next working day rather than a closed one', function () {
    settings()->set('scheduling.working_week', 'mon_fri');
    // A Saturday.
    Carbon::setTestNow('2026-10-17 09:00:00');

    $contact = bookableContact();

    Livewire::actingAs(booker())
        ->test(BookMeeting::class, ['module' => 'contacts', 'record' => $contact->id])
        ->assertSet('date', '2026-10-19');
});
