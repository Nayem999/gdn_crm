<?php

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\PermissionResolver;
use App\Domain\Accounts\Models\Account;
use App\Domain\Activities\Actions\CancelActivityAction;
use App\Domain\Activities\Actions\CompleteActivityAction;
use App\Domain\Activities\Actions\CreateActivityAction;
use App\Domain\Activities\Actions\DeleteActivityAction;
use App\Domain\Activities\Actions\ReopenActivityAction;
use App\Domain\Activities\Actions\UpdateActivityAction;
use App\Domain\Activities\ActivityMergeData;
use App\Domain\Activities\ActivityRelations;
use App\Domain\Activities\DTOs\ActivityData;
use App\Domain\Activities\Enums\ActivityPriority;
use App\Domain\Activities\Enums\ActivityStatus;
use App\Domain\Activities\Enums\ActivityType;
use App\Domain\Activities\Models\Activity;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Models\Lead;
use App\Domain\Notifications\NotificationEventRegistry;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

/**
 * Somebody who runs the activities module, and can see every record it can be
 * about — the record picker is only meaningful against records they can reach.
 *
 * @param  array<int, string>  $permissions
 */
function activityAdmin(?array $permissions = null): User
{
    $permissions ??= [
        'activities.view', 'activities.create', 'activities.update',
        'activities.assign', 'activities.delete', 'activities.export',
        'accounts.view', 'contacts.view', 'leads.view', 'deals.view',
    ];

    $role = Role::query()->create([
        'name' => 'Activities all '.uniqid(),
        'guard_name' => Guard::getDefaultName(Role::class),
        'data_access_level' => DataAccessLevel::All->value,
    ]);

    $role->syncPermissions(PermissionResolver::models($permissions));

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * @param  array<int, string>  $permissions
 */
function activityUser(array $permissions = ['activities.view']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function activityData(array $overrides = []): ActivityData
{
    return ActivityData::fromArray([
        'type' => 'call',
        'subject' => 'Call Dana about the renewal',
        'priority' => (string) ActivityPriority::High->value,
        'due_at' => '2026-10-01 14:30',
        'duration_minutes' => '30',
        ...$overrides,
    ]);
}

function createActivity(?ActivityData $data = null, ?User $actor = null): Activity
{
    $actor ??= activityAdmin();

    return app(CreateActivityAction::class)($data ?? activityData(), $actor);
}

// -- Creating ------------------------------------------------------------------

test('an activity is created with what the form carried', function () {
    $actor = activityAdmin();

    $activity = createActivity(actor: $actor);

    expect($activity->subject)->toBe('Call Dana about the renewal')
        ->and($activity->type())->toBe(ActivityType::Call)
        ->and($activity->priority())->toBe(ActivityPriority::High)
        ->and($activity->status())->toBe(ActivityStatus::Open)
        ->and($activity->due_at->format('Y-m-d H:i'))->toBe('2026-10-01 14:30')
        ->and($activity->duration_minutes)->toBe(30)
        ->and($activity->created_by_id)->toBe($actor->id);
});

test('an activity created without an owner belongs to whoever created it', function () {
    $actor = activityAdmin();

    expect(createActivity(actor: $actor)->owner_id)->toBe($actor->id);
});

test('an owner named on the form is the one stored', function () {
    $actor = activityAdmin();
    $colleague = User::factory()->create();

    $activity = createActivity(activityData(['owner_id' => (string) $colleague->id]), $actor);

    expect($activity->owner_id)->toBe($colleague->id);
});

test('an all-day activity is pinned to the start of its day', function () {
    // Two all-day activities on one date should sort together rather than by
    // whatever time the form happened to submit.
    $activity = createActivity(activityData([
        'all_day' => true,
        'due_at' => '2026-10-01 14:30',
    ]));

    expect($activity->due_at->format('Y-m-d H:i:s'))->toBe('2026-10-01 00:00:00')
        ->and($activity->all_day)->toBeTrue();
});

test('a field the kind of activity has no use for is not stored', function () {
    // A task cannot carry a duration or a room booking: they would sit in the
    // record unread, and the calendar would give a task a slot it never wanted.
    $activity = createActivity(activityData([
        'type' => 'task',
        'duration_minutes' => '45',
        'location' => 'Their office',
    ]));

    expect($activity->duration_minutes)->toBeNull()
        ->and($activity->location)->toBeNull();
});

test('a call keeps its duration but not a location', function () {
    $activity = createActivity(activityData([
        'type' => 'call',
        'duration_minutes' => '15',
        'location' => 'Their office',
    ]));

    expect($activity->duration_minutes)->toBe(15)
        ->and($activity->location)->toBeNull();
});

test('a meeting keeps both', function () {
    $activity = createActivity(activityData([
        'type' => 'meeting',
        'duration_minutes' => '60',
        'location' => 'Their office',
    ]));

    expect($activity->duration_minutes)->toBe(60)
        ->and($activity->location)->toBe('Their office');
});

// -- What it is about ----------------------------------------------------------

test('an activity can be about any record the registry lists', function (string $module) {
    $actor = activityAdmin();
    $record = match ($module) {
        'leads' => Lead::factory()->create(),
        'contacts' => Contact::factory()->create(),
        'accounts' => Account::factory()->create(),
        'deals' => Deal::factory()->create(),
    };

    $activity = createActivity(activityData([
        'related_module' => $module,
        'related_id' => (string) $record->id,
    ]), $actor);

    expect($activity->related_type)->toBe($record->getMorphClass())
        ->and($activity->related_id)->toBe($record->id)
        ->and($activity->related->is($record))->toBeTrue();
    // A closure, not a plain array: Pest resolves a dataset at collection time,
    // before the application is booted.
})->with(fn () => ActivityRelations::keys());

test('a module the registry does not list is refused, never turned into a class', function () {
    // The form carries a module key and this is the only place it is matched.
    // Anything else would be a request naming a class.
    expect(fn () => createActivity(activityData([
        'related_module' => 'users',
        'related_id' => '1',
    ])))->toThrow(RuntimeException::class, 'not a record an activity can be about');
});

test('a record outside the creator access level cannot be attached by guessing its id', function () {
    $stranger = Account::factory()->create();
    // Own-level access, so somebody else's account is invisible to them.
    $actor = activityUser(['activities.view', 'activities.create', 'accounts.view']);

    expect(fn () => createActivity(activityData([
        'related_module' => 'accounts',
        'related_id' => (string) $stranger->id,
    ]), $actor))->toThrow(RuntimeException::class, 'not one you can work with');
});

test('the related record can be cleared on an update', function () {
    $actor = activityAdmin();
    $account = Account::factory()->create();
    $activity = createActivity(activityData([
        'related_module' => 'accounts',
        'related_id' => (string) $account->id,
    ]), $actor);

    app(UpdateActivityAction::class)($activity, activityData(), $actor);

    expect($activity->fresh()->related_type)->toBeNull()
        ->and($activity->fresh()->related_id)->toBeNull();
});

// -- Status is owned by the actions -------------------------------------------

test('status is not fillable, so no form or payload can declare something done', function () {
    $activity = Activity::factory()->create();

    $activity->fill(['status' => ActivityStatus::Completed->value]);

    expect($activity->status())->toBe(ActivityStatus::Open);
});

test('ActivityData carries no status, completed_at or completion notes', function () {
    $carried = array_keys(get_object_vars(activityData()));

    expect($carried)->not->toContain('status')
        ->and($carried)->not->toContain('completedAt')
        ->and($carried)->not->toContain('completionNotes');
});

test('completing stamps when it was finished', function () {
    Carbon::setTestNow('2026-10-01 09:00:00');
    $activity = Activity::factory()->create();

    expect(app(CompleteActivityAction::class)($activity, ' Left a voicemail '))->toBeTrue();

    $activity->refresh();

    expect($activity->status())->toBe(ActivityStatus::Completed)
        ->and($activity->completed_at?->format('Y-m-d H:i:s'))->toBe('2026-10-01 09:00:00')
        ->and($activity->completion_notes)->toBe('Left a voicemail');
});

test('completing something already completed changes nothing and reports no move', function () {
    Carbon::setTestNow('2026-10-01 09:00:00');
    $activity = Activity::factory()->create();
    app(CompleteActivityAction::class)($activity);

    Carbon::setTestNow('2026-10-08 09:00:00');

    // False rather than pushing the stamp a week forward — the same reason
    // re-clicking a deal's closing stage is a no-op.
    expect(app(CompleteActivityAction::class)($activity))->toBeFalse()
        ->and($activity->fresh()->completed_at?->format('Y-m-d'))->toBe('2026-10-01');
});

test('reopening clears the stamp, the notes and a reminder already sent', function () {
    $activity = Activity::factory()->remindingAfter(30)->create(['reminder_sent_at' => now()]);
    app(CompleteActivityAction::class)($activity, 'Done');

    expect(app(ReopenActivityAction::class)($activity))->toBeTrue();

    $activity->refresh();

    expect($activity->status())->toBe(ActivityStatus::Open)
        ->and($activity->completed_at)->toBeNull()
        // An open task that still reads "done" describes something untrue.
        ->and($activity->completion_notes)->toBeNull()
        // And the next reminder must not be silenced by the last one.
        ->and($activity->reminder_sent_at)->toBeNull();
});

test('cancelling is not completing', function () {
    $activity = Activity::factory()->create();

    expect(app(CancelActivityAction::class)($activity, 'They pulled out'))->toBeTrue();

    $activity->refresh();

    expect($activity->status())->toBe(ActivityStatus::Cancelled)
        // A cancelled meeting counting towards "completed this week" would
        // overstate the work done.
        ->and($activity->completed_at)->toBeNull()
        ->and($activity->completion_notes)->toBe('They pulled out');
});

test('a cancelled activity can be put back on the list', function () {
    $activity = Activity::factory()->create();
    app(CancelActivityAction::class)($activity);

    expect(app(ReopenActivityAction::class)($activity))->toBeTrue()
        ->and($activity->fresh()->status())->toBe(ActivityStatus::Open);
});

// -- Overdue is derived, never stored -----------------------------------------

test('overdue is the clock and the status, not a column', function () {
    Carbon::setTestNow('2026-10-01 12:00:00');

    $late = Activity::factory()->create(['due_at' => '2026-09-30 09:00']);
    $ahead = Activity::factory()->create(['due_at' => '2026-10-02 09:00']);
    $done = Activity::factory()->completed()->create(['due_at' => '2026-09-30 09:00']);

    expect($late->isOverdue())->toBeTrue()
        ->and($ahead->isOverdue())->toBeFalse()
        // Finished is not late, however long ago it was due.
        ->and($done->isOverdue())->toBeFalse();

    expect(Activity::query()->overdue()->pluck('id')->all())->toBe([$late->id]);
});

test('an all-day activity is not overdue until its day has gone', function () {
    Carbon::setTestNow('2026-10-01 12:00:00');

    // Due "today" at 00:00 is not late at lunchtime.
    $today = Activity::factory()->allDay('2026-10-01')->create();
    $yesterday = Activity::factory()->allDay('2026-09-30')->create();

    expect($today->isOverdue())->toBeFalse()
        ->and($yesterday->isOverdue())->toBeTrue();

    // And the scope makes the same allowance, so the list and the record cannot
    // disagree about what is late.
    expect(Activity::query()->overdue()->pluck('id')->all())->toBe([$yesterday->id]);
});

// -- Deleting ------------------------------------------------------------------

test('deleting an activity keeps the row', function () {
    $activity = Activity::factory()->create();

    app(DeleteActivityAction::class)($activity);

    expect(Activity::query()->whereKey($activity->id)->exists())->toBeFalse()
        ->and(Activity::query()->withTrashed()->whereKey($activity->id)->exists())->toBeTrue();
});

// -- Access --------------------------------------------------------------------

test('an activity outside the access level is not visible', function () {
    $mine = Activity::factory()->create();
    $theirs = Activity::factory()->create();
    $viewer = activityUser(['activities.view']);
    $mine->forceFill(['owner_id' => $viewer->id])->save();

    $visible = Activity::query()->visibleTo($viewer)->pluck('id')->all();

    expect($visible)->toBe([$mine->id])
        ->and($viewer->can('view', $mine))->toBeTrue()
        ->and($viewer->can('view', $theirs))->toBeFalse();
});

test('every activity permission is declared in the catalogue', function () {
    // The roles matrix and the seeder both read the catalogue, so a permission
    // a policy checks but the catalogue does not list can never be granted.
    foreach (['view', 'create', 'update', 'assign', 'delete', 'export'] as $action) {
        expect(PermissionCatalogue::has('activities.'.$action))->toBeTrue();
    }
});

test('the module cannot be reached without its permission', function () {
    $outsider = User::factory()->create();
    $activity = Activity::factory()->create();

    expect($outsider->can('viewAny', Activity::class))->toBeFalse()
        ->and($outsider->can('create', Activity::class))->toBeFalse()
        ->and($outsider->can('update', $activity))->toBeFalse()
        ->and($outsider->can('delete', $activity))->toBeFalse();
});

test('completing sits under update rather than a permission of its own', function () {
    // Somebody who may not mark their own call done cannot use the module, so
    // there is deliberately no activities.complete.
    $user = activityUser(['activities.view', 'activities.update']);
    $activity = Activity::factory()->ownedBy($user)->create();

    expect($user->can('update', $activity))->toBeTrue()
        ->and(PermissionCatalogue::has('activities.complete'))->toBeFalse();
});

// -- Audit ---------------------------------------------------------------------

test('an activity records audit entries under its own label', function () {
    $activity = Activity::factory()->create();

    // Both things in this application are called "Activity" — the audit viewer
    // has to be able to tell them apart.
    expect(Activity::activitySubjectLabel())->toBe('Task, call or meeting')
        ->and($activity->activities()->count())->toBeGreaterThan(0);
});

test('the audit allowlist never grows implicitly', function () {
    $activity = Activity::factory()->create();
    $logged = (new ReflectionClass(Activity::class))->getMethod('activityAttributes');
    $logged->setAccessible(true);

    /** @var array<int, string> $attributes */
    $attributes = $logged->invoke($activity);

    // Opt-in, never logAll(): a column added later records nothing until
    // somebody lists it here.
    expect($attributes)->toContain('status')
        ->and($attributes)->not->toContain('completion_notes')
        ->and($attributes)->not->toContain('description');
});

// -- Notifications -------------------------------------------------------------

test('both activity events are registered, and their merge fields match what is supplied', function (string $event) {
    $registered = NotificationEventRegistry::find($event);

    expect($registered)->not->toBeNull();

    if ($registered === null) {
        return;
    }

    $activity = Activity::factory()->create();
    $supplied = ActivityMergeData::for($activity)['activity'];

    expect(array_keys($registered->mergeFields))->not->toBeEmpty();

    foreach (array_keys($registered->mergeFields) as $field) {
        // A template naming a field the event does not declare is refused at
        // save time; a field declared but never supplied renders as a gap in a
        // message somebody reads.
        expect($supplied)->toHaveKey(str_replace('activity.', '', $field));
    }
})->with(['activity.assigned', 'activity.reminder']);

test('assigning to somebody else tells them, and assigning to yourself does not', function () {
    $actor = activityAdmin();
    $colleague = User::factory()->create();

    createActivity(activityData(['owner_id' => (string) $actor->id]), $actor);

    // An actor is never notified about their own action.
    expect($colleague->unreadNotifications()->count())->toBe(0);

    createActivity(activityData(['owner_id' => (string) $colleague->id]), $actor);

    expect($colleague->unreadNotifications()->count())->toBe(1);
});
