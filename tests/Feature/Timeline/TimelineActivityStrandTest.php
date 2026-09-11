<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Activities\Models\Activity;
use App\Domain\Company\Models\Company;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Domain\Timeline\Enums\TimelineEntryKind;
use App\Domain\Timeline\TimelineBuilder;
use App\Domain\Timeline\TimelinePage;
use App\Livewire\Timeline\RecordTimeline;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

/**
 * @param  array<int, string>  $permissions
 */
function strandUser(array $permissions, string $level = DataAccessLevel::All->value): User
{
    $role = Role::query()->create([
        'name' => 'Strand '.uniqid(),
        'guard_name' => Guard::getDefaultName(Role::class),
        'data_access_level' => $level,
    ]);

    $role->syncPermissions(PermissionResolver::models($permissions));

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

function strandOperator(): User
{
    return strandUser([
        'timeline.view', 'timeline.create', 'timeline.update', 'timeline.delete',
        'contacts.view', 'activities.view',
    ]);
}

/**
 * @param  array<int, TimelineEntryKind>|null  $kinds
 */
function strandTimeline(Model $subject, User $viewer, ?array $kinds = null): TimelinePage
{
    return app(TimelineBuilder::class)->for($subject, 20, $kinds, $viewer);
}

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow('2026-10-14 09:00:00');
    Company::current()->forceFill(['timezone' => 'UTC'])->save();
    Cache::flush();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('a scheduled activity appears on the record it is about', function () {
    $viewer = strandOperator();
    $contact = Contact::factory()->create();

    Activity::factory()->ownedBy($viewer)->meeting()->about($contact)->create([
        'subject' => 'Renewal discussion',
        'due_at' => '2026-10-20 11:00:00',
    ]);

    $page = strandTimeline($contact, $viewer, [TimelineEntryKind::Activity]);

    expect($page->entries)->toHaveCount(1)
        ->and($page->entries[0]->kind)->toBe(TimelineEntryKind::Activity)
        ->and($page->entries[0]->title)->toBe('Meeting: Renewal discussion')
        ->and($page->activityCount)->toBe(1);
});

test('an activity about a different record does not leak onto this one', function () {
    $viewer = strandOperator();
    $contact = Contact::factory()->create();
    $other = Contact::factory()->create();

    Activity::factory()->ownedBy($viewer)->about($other)->create(['subject' => 'Somebody else']);

    expect(strandTimeline($contact, $viewer, [TimelineEntryKind::Activity])->entries)->toBe([]);
});

test('the strand is placed by when it is due, not when it was written', function () {
    $viewer = strandOperator();
    $contact = Contact::factory()->create();

    $activity = Activity::factory()->ownedBy($viewer)->about($contact)->create([
        'subject' => 'Next week',
        'due_at' => '2026-10-20 11:00:00',
    ]);

    $entry = strandTimeline($contact, $viewer, [TimelineEntryKind::Activity])->entries[0];

    expect($entry->occurredAt->format('Y-m-d H:i'))->toBe('2026-10-20 11:00')
        ->and($entry->occurredAt->format('Y-m-d'))->not->toBe($activity->created_at?->format('Y-m-d'));
});

test('the strand reads on the office clock, like the calendar does', function () {
    Company::current()->forceFill(['timezone' => 'Asia/Dhaka'])->save();
    Cache::flush();

    $viewer = strandOperator();
    $contact = Contact::factory()->create();

    Activity::factory()->ownedBy($viewer)->about($contact)->create([
        'subject' => 'Late one',
        'due_at' => '2026-10-20 19:30:00',
    ]);

    // 19:30 UTC is 01:30 the next morning in Dhaka, and the timeline has to say
    // the same thing the calendar cell does.
    expect(strandTimeline($contact, $viewer, [TimelineEntryKind::Activity])->entries[0]->occurredLabel())
        ->toContain('21 Oct 2026')
        ->toContain('01:30');
});

test('somebody without the activities permission gets no strand and no chip', function () {
    $viewer = strandUser(['timeline.view', 'contacts.view']);
    $contact = Contact::factory()->create();

    Activity::factory()->about($contact)->create(['subject' => 'Private business']);

    $page = strandTimeline($contact, $viewer, [TimelineEntryKind::Activity]);

    expect($page->entries)->toBe([])
        ->and($page->activityCount)->toBe(0);

    Livewire::actingAs($viewer)
        ->test(RecordTimeline::class, ['module' => 'contacts', 'record' => $contact->id])
        ->assertOk()
        ->assertDontSee('Activities');
});

test('the strand respects the activities access level, not just the record', function () {
    // Can see every contact, but only their own activities.
    $viewer = strandUser(['timeline.view', 'contacts.view', 'activities.view'], DataAccessLevel::Own->value);
    $contact = Contact::factory()->create();

    Activity::factory()->ownedBy($viewer)->about($contact)->create(['subject' => 'My call']);
    Activity::factory()->about($contact)->create(['subject' => 'Their call']);

    $page = strandTimeline($contact, $viewer, [TimelineEntryKind::Activity]);

    expect($page->entries)->toHaveCount(1)
        ->and($page->entries[0]->title)->toContain('My call')
        ->and($page->activityCount)->toBe(1);
});

test('a builder called without a viewer leaves the strand out rather than guessing', function () {
    $contact = Contact::factory()->create();

    Activity::factory()->about($contact)->create(['subject' => 'Unscoped']);

    $page = app(TimelineBuilder::class)->for($contact, 20, [TimelineEntryKind::Activity]);

    expect($page->entries)->toBe([])
        ->and($page->activityCount)->toBe(0);
});

test('the strand appears on the record screen with its own chip', function () {
    $viewer = strandOperator();
    $contact = Contact::factory()->create();

    Activity::factory()->ownedBy($viewer)->meeting()->about($contact)->create([
        'subject' => 'Renewal discussion',
        'due_at' => '2026-10-20 11:00:00',
    ]);

    Livewire::actingAs($viewer)
        ->test(RecordTimeline::class, ['module' => 'contacts', 'record' => $contact->id])
        ->assertOk()
        ->assertSee('Activities')
        ->assertSee('Meeting: Renewal discussion')
        ->call('toggleKind', 'activity')
        ->assertSee('Meeting: Renewal discussion')
        ->call('toggleKind', 'activity')
        ->call('toggleKind', 'note')
        ->assertDontSee('Meeting: Renewal discussion');
});
