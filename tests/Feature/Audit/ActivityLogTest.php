<?php

use App\Domain\Access\Actions\CreateRoleAction;
use App\Domain\Access\Actions\DeleteRoleAction;
use App\Domain\Access\Actions\UpdateRoleAction;
use App\Domain\Access\DTOs\RoleData;
use App\Domain\Audit\AuditLogger;
use App\Domain\Company\Models\Company;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Domain\Users\Models\UserInvitation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/**
 * @return array<int, Activity>
 */
function auditEntriesFor(string $subjectType): array
{
    return Activity::query()
        ->where('log_name', AuditLogger::LOG_NAME)
        ->where('subject_type', $subjectType)
        ->orderBy('id')
        ->get()
        ->all();
}

test('creating, updating and deleting a team each write one audit entry', function () {
    $team = Team::factory()->create(['name' => 'Revenue']);
    $team->update(['name' => 'Inside Sales']);
    $team->delete();

    $events = collect(auditEntriesFor(Team::class))->pluck('event')->all();

    expect($events)->toBe(['created', 'updated', 'deleted']);
});

test('creating, updating and deleting a user each write one audit entry', function () {
    $user = User::factory()->create(['name' => 'Ada']);
    $user->update(['name' => 'Ada Lovelace']);
    $user->delete();

    $events = collect(auditEntriesFor(User::class))->pluck('event')->all();

    expect($events)->toBe(['created', 'updated', 'deleted']);
});

test('company profile changes are audited', function () {
    $company = Company::current();
    $company->update(['name' => 'Golden Info Tech']);

    $entries = auditEntriesFor(Company::class);

    expect($entries)->toHaveCount(2)
        ->and($entries[1]->event)->toBe('updated')
        ->and($entries[1]->properties['attributes']['name'])->toBe('Golden Info Tech');
});

test('invitations are audited', function () {
    $invitation = UserInvitation::factory()->create(['email' => 'newcomer@example.com']);
    $invitation->update(['name' => 'New Comer']);

    $events = collect(auditEntriesFor(UserInvitation::class))->pluck('event')->all();

    expect($events)->toBe(['created', 'updated']);
});

test('an update records the old and the new value', function () {
    $team = Team::factory()->create(['name' => 'Revenue']);
    $team->update(['name' => 'Inside Sales']);

    $entry = collect(auditEntriesFor(Team::class))->firstWhere('event', 'updated');

    expect($entry->properties['attributes']['name'])->toBe('Inside Sales')
        ->and($entry->properties['old']['name'])->toBe('Revenue');
});

test('an update that changes nothing auditable writes no entry', function () {
    $user = User::factory()->create();

    $countAfterCreate = count(auditEntriesFor(User::class));

    // remember_token is not an audited attribute, so this must stay silent.
    $user->forceFill(['remember_token' => 'a-new-token'])->save();

    expect(auditEntriesFor(User::class))->toHaveCount($countAfterCreate);
});

test('the audit trail never records credentials', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-battery')]);

    $user->forceFill([
        'password' => Hash::make('a-different-password'),
        'remember_token' => 'token-value',
        'two_factor_secret' => encrypt('the-secret'),
        'two_factor_recovery_codes' => encrypt('["code"]'),
    ])->save();

    $user->update(['name' => 'Still Audited']);

    $invitation = UserInvitation::factory()->create();
    $invitation->update(['name' => 'Renamed']);

    $payloads = Activity::query()->pluck('properties')->map(fn ($properties) => json_encode($properties))->implode(' ');

    expect($payloads)->not->toContain('password')
        ->and($payloads)->not->toContain('remember_token')
        ->and($payloads)->not->toContain('two_factor_secret')
        ->and($payloads)->not->toContain('two_factor_recovery_codes')
        // The invitation token is a credential too.
        ->and($payloads)->not->toContain('token')
        // ...while ordinary attributes are still there.
        ->and($payloads)->toContain('Still Audited');
});

test('the acting user is recorded as the causer', function () {
    $actor = User::factory()->create(['name' => 'Acting Admin']);

    $this->actingAs($actor);

    $team = Team::factory()->create();

    $entry = collect(auditEntriesFor(Team::class))->firstWhere('event', 'created');

    expect($entry->causer_id)->toBe($actor->id)
        ->and($entry->causer_type)->toBe(User::class)
        ->and($entry->causer->name)->toBe('Acting Admin');
});

test('changes made with nobody signed in have no causer', function () {
    $team = Team::factory()->create();

    $entry = collect(auditEntriesFor(Team::class))->firstWhere('event', 'created');

    expect($entry->causer)->toBeNull();
});

test('entries are written under the audit log name', function () {
    Team::factory()->create();

    expect(Activity::query()->where('log_name', AuditLogger::LOG_NAME)->count())->toBeGreaterThan(0);
});

test('role create, update and delete are each audited', function () {
    $this->actingAs(User::factory()->create());

    $role = app(CreateRoleAction::class)(new RoleData(
        name: 'Sales Rep',
        dataAccessLevel: DataAccessLevel::Own,
        permissions: ['users.view'],
    ));

    app(UpdateRoleAction::class)($role, new RoleData(
        name: 'Senior Sales Rep',
        dataAccessLevel: DataAccessLevel::Team,
        permissions: ['users.view', 'teams.view'],
    ));

    app(DeleteRoleAction::class)($role->refresh());

    $entries = auditEntriesFor(Role::class);

    expect(collect($entries)->pluck('event')->all())->toBe(['created', 'updated', 'deleted']);

    $updated = collect($entries)->firstWhere('event', 'updated');

    expect($updated->properties['old']['name'])->toBe('Sales Rep')
        ->and($updated->properties['attributes']['name'])->toBe('Senior Sales Rep')
        ->and($updated->properties['old']['data_access_level'])->toBe(DataAccessLevel::Own->value)
        ->and($updated->properties['attributes']['data_access_level'])->toBe(DataAccessLevel::Team->value)
        ->and($updated->properties['attributes']['permissions'])->toEqualCanonicalizing(['users.view', 'teams.view']);
});

test('a role update that changes nothing writes no entry', function () {
    $role = app(CreateRoleAction::class)(new RoleData(
        name: 'Sales Rep',
        dataAccessLevel: DataAccessLevel::Own,
        permissions: ['users.view'],
    ));

    $countAfterCreate = count(auditEntriesFor(Role::class));

    app(UpdateRoleAction::class)($role, new RoleData(
        name: 'Sales Rep',
        dataAccessLevel: DataAccessLevel::Own,
        permissions: ['users.view'],
    ));

    expect(auditEntriesFor(Role::class))->toHaveCount($countAfterCreate);
});

test('a deleted subject still resolves so the trail stays readable', function () {
    $user = User::factory()->create(['name' => 'Departed']);
    $user->delete();

    $entry = collect(auditEntriesFor(User::class))->firstWhere('event', 'deleted');

    // config('activitylog.subject_returns_soft_deleted_models') is on for this.
    expect($entry->subject)->not->toBeNull()
        ->and($entry->subject->name)->toBe('Departed');
});
