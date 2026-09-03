<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Actions\CreateContactAction;
use App\Domain\Contacts\Actions\DeleteContactAction;
use App\Domain\Contacts\Actions\SetPrimaryContactAction;
use App\Domain\Contacts\Actions\UpdateContactAction;
use App\Domain\Contacts\DTOs\ContactData;
use App\Domain\Contacts\Enums\Department;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Domain\Shared\UI\ChipPalette;
use App\Models\Team;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

// -- The enum ------------------------------------------------------------------

test('every department has a label, a palette colour and an option', function (Department $department) {
    expect($department->label())->not->toBeEmpty()
        ->and(ChipPalette::has($department->color()))->toBeTrue()
        ->and(Department::options())->toHaveKey($department->value);
})->with(Department::cases());

// -- The model -----------------------------------------------------------------

test('a contact reads its name, initials and department', function () {
    $contact = Contact::factory()
        ->named('Dana', 'Scully')
        ->department(Department::Executive)
        ->create();

    expect($contact->fullName())->toBe('Dana Scully')
        ->and($contact->initials())->toBe('DS')
        ->and($contact->department())->toBe(Department::Executive);
});

test('an unrecognised department reads as null rather than throwing', function () {
    $contact = Contact::factory()->create();
    $contact->forceFill(['department' => 'time-travel'])->save();

    expect($contact->fresh()->department())->toBeNull();
});

test('the role line combines job title and account, skipping what is missing', function () {
    $account = Account::factory()->create(['name' => 'Acme Corporation']);

    expect(Contact::factory()->forAccount($account)->create(['job_title' => 'CFO'])->roleLine())
        ->toBe('CFO · Acme Corporation')
        ->and(Contact::factory()->create(['job_title' => 'CFO'])->roleLine())->toBe('CFO')
        ->and(Contact::factory()->forAccount($account)->create(['job_title' => null])->roleLine())
        ->toBe('Acme Corporation')
        ->and(Contact::factory()->create(['job_title' => null])->roleLine())->toBeNull();
});

test('a contact may exist with no account', function () {
    $contact = Contact::factory()->create(['account_id' => null]);

    expect($contact->account_id)->toBeNull()
        ->and($contact->account)->toBeNull();
});

test('search matches either name, the two together, email and phone', function () {
    Contact::factory()->named('Dana', 'Scully')->create(['email' => 'dana@example.test', 'phone' => '0113 111']);
    Contact::factory()->named('Fox', 'Mulder')->create(['email' => 'fox@example.test', 'phone' => '0113 222']);
    Contact::factory()->named('Walter', 'Skinner')->create(['job_title' => 'Assistant Director']);

    expect(Contact::query()->search('Dana')->count())->toBe(1)
        ->and(Contact::query()->search('Scully')->count())->toBe(1)
        // The whole name, which neither column holds on its own.
        ->and(Contact::query()->search('Dana Scully')->count())->toBe(1)
        ->and(Contact::query()->search('fox@example.test')->count())->toBe(1)
        ->and(Contact::query()->search('0113')->count())->toBe(2)
        ->and(Contact::query()->search('Assistant')->count())->toBe(1)
        ->and(Contact::query()->search('')->count())->toBe(3);
});

test('removing an account leaves its contacts standing', function () {
    $account = Account::factory()->create();
    $contact = Contact::factory()->forAccount($account)->create();

    $account->forceDelete();

    expect($contact->fresh())->not->toBeNull()
        ->and($contact->fresh()->account_id)->toBeNull();
});

// -- The primary contact invariant ---------------------------------------------

test('the first contact at an account becomes its primary', function () {
    $account = Account::factory()->create();
    $actor = User::factory()->create();

    $first = app(CreateContactAction::class)(
        ContactData::fromArray(['first_name' => 'Dana', 'last_name' => 'Scully', 'account_id' => $account->id]),
        $actor
    );

    expect($first->is_primary)->toBeTrue();
});

test('a second contact does not take the primary flag unasked', function () {
    $account = Account::factory()->create();
    $actor = User::factory()->create();
    $create = app(CreateContactAction::class);

    $first = $create(ContactData::fromArray([
        'first_name' => 'Dana', 'last_name' => 'Scully', 'account_id' => $account->id,
    ]), $actor);

    $second = $create(ContactData::fromArray([
        'first_name' => 'Fox', 'last_name' => 'Mulder', 'account_id' => $account->id,
    ]), $actor);

    expect($first->fresh()->is_primary)->toBeTrue()
        ->and($second->is_primary)->toBeFalse();
});

test('promoting one contact demotes whoever held it', function () {
    $account = Account::factory()->create();
    $first = Contact::factory()->forAccount($account)->primary()->create();
    $second = Contact::factory()->forAccount($account)->create();

    app(SetPrimaryContactAction::class)->promote($second);

    expect($first->fresh()->is_primary)->toBeFalse()
        ->and($second->fresh()->is_primary)->toBeTrue()
        ->and(Contact::query()->where('account_id', $account->id)->primary()->count())->toBe(1);
});

test('promotion never reaches another account', function () {
    $ours = Account::factory()->create();
    $theirs = Account::factory()->create();

    $theirPrimary = Contact::factory()->forAccount($theirs)->primary()->create();
    $ourContact = Contact::factory()->forAccount($ours)->create();

    app(SetPrimaryContactAction::class)->promote($ourContact);

    expect($theirPrimary->fresh()->is_primary)->toBeTrue()
        ->and($ourContact->fresh()->is_primary)->toBeTrue();
});

test('a contact with no account cannot be primary', function () {
    $contact = Contact::factory()->create(['account_id' => null]);

    expect(app(SetPrimaryContactAction::class)->promote($contact))->toBeFalse()
        ->and($contact->fresh()->is_primary)->toBeFalse();
});

test('a request to be primary is honoured on create', function () {
    $account = Account::factory()->create();
    $existing = Contact::factory()->forAccount($account)->primary()->create();

    $created = app(CreateContactAction::class)(
        ContactData::fromArray([
            'first_name' => 'Fox', 'last_name' => 'Mulder',
            'account_id' => $account->id, 'is_primary' => true,
        ]),
        User::factory()->create()
    );

    expect($created->is_primary)->toBeTrue()
        ->and($existing->fresh()->is_primary)->toBeFalse();
});

test('moving a contact to another account drops the flag and backfills the old one', function () {
    $from = Account::factory()->create();
    $to = Account::factory()->create();

    $mover = Contact::factory()->forAccount($from)->primary()->create();
    $colleague = Contact::factory()->forAccount($from)->create();

    app(UpdateContactAction::class)($mover, ContactData::fromArray([
        'first_name' => $mover->first_name,
        'last_name' => $mover->last_name,
        'account_id' => $to->id,
    ]));

    // Primary at the new account because it had nobody, and the old account's
    // remaining contact takes over there.
    expect($mover->fresh()->account_id)->toBe($to->id)
        ->and($mover->fresh()->is_primary)->toBeTrue()
        ->and($colleague->fresh()->is_primary)->toBeTrue();
});

test('removing the primary contact passes the flag to the longest-standing colleague', function () {
    $account = Account::factory()->create();

    $primary = Contact::factory()->forAccount($account)->primary()->create(['created_at' => now()->subDays(5)]);
    $older = Contact::factory()->forAccount($account)->create(['created_at' => now()->subDays(3)]);
    $newer = Contact::factory()->forAccount($account)->create(['created_at' => now()->subDay()]);

    app(DeleteContactAction::class)($primary);

    expect($primary->fresh()->trashed())->toBeTrue()
        ->and($older->fresh()->is_primary)->toBeTrue()
        ->and($newer->fresh()->is_primary)->toBeFalse();
});

test('removing the last contact leaves nothing to backfill', function () {
    $account = Account::factory()->create();
    $only = Contact::factory()->forAccount($account)->primary()->create();

    app(DeleteContactAction::class)($only);

    expect(Contact::query()->where('account_id', $account->id)->count())->toBe(0);
});

test('an account never ends up with two primaries however contacts are added', function () {
    $account = Account::factory()->create();
    $actor = User::factory()->create();
    $create = app(CreateContactAction::class);

    foreach (range(1, 5) as $index) {
        $create(ContactData::fromArray([
            'first_name' => 'Person'.$index,
            'last_name' => 'Test',
            'account_id' => $account->id,
            // Every other one asks to be primary.
            'is_primary' => $index % 2 === 0,
        ]), $actor);
    }

    expect(Contact::query()->where('account_id', $account->id)->primary()->count())->toBe(1);
});

// -- Actions -------------------------------------------------------------------

test('creating a contact defaults the owner to whoever created it', function () {
    $actor = User::factory()->create();

    $contact = app(CreateContactAction::class)(
        ContactData::fromArray(['first_name' => 'Dana', 'last_name' => 'Scully']),
        $actor
    );

    expect($contact->owner_id)->toBe($actor->id);
});

test('creating with an account that does not exist is refused', function () {
    expect(fn () => app(CreateContactAction::class)(
        ContactData::fromArray(['first_name' => 'Dana', 'last_name' => 'Scully', 'account_id' => 9999]),
        User::factory()->create()
    ))->toThrow(RuntimeException::class, 'does not exist');
});

test('an update clears a field the user emptied', function () {
    $contact = Contact::factory()->create(['phone' => '0113 000 0000']);

    app(UpdateContactAction::class)($contact, ContactData::fromArray([
        'first_name' => $contact->first_name,
        'last_name' => $contact->last_name,
    ]));

    expect($contact->fresh()->phone)->toBeNull();
});

test('an update that omits the owner leaves it alone', function () {
    $owner = User::factory()->create();
    $contact = Contact::factory()->ownedBy($owner)->create();

    app(UpdateContactAction::class)($contact, ContactData::fromArray([
        'first_name' => 'Renamed',
        'last_name' => $contact->last_name,
    ]));

    expect($contact->fresh()->owner_id)->toBe($owner->id)
        ->and($contact->fresh()->first_name)->toBe('Renamed');
});

test('an update to an account that does not exist is refused', function () {
    $contact = Contact::factory()->create();

    expect(fn () => app(UpdateContactAction::class)($contact, ContactData::fromArray([
        'first_name' => $contact->first_name,
        'last_name' => $contact->last_name,
        'account_id' => 9999,
    ])))->toThrow(RuntimeException::class, 'does not exist');
});

// -- Access level --------------------------------------------------------------

/**
 * @param  array<string, mixed>  $attributes
 */
function contactUserWithLevel(DataAccessLevel $level, array $attributes = []): User
{
    $user = User::factory()->create($attributes);

    $role = Role::query()->create([
        'name' => 'Level '.$level->value.' '.uniqid(),
        'guard_name' => Guard::getDefaultName(Role::class),
        'data_access_level' => $level->value,
    ]);

    $user->assignRole($role);

    return $user->fresh();
}

test('own access sees only their own contacts', function () {
    $user = contactUserWithLevel(DataAccessLevel::Own);

    Contact::factory()->ownedBy($user)->named('Mine', 'Contact')->create();
    Contact::factory()->named('Their', 'Contact')->create();

    expect(Contact::query()->visibleTo($user)->pluck('first_name')->all())->toBe(['Mine']);
});

test('team access sees the whole active team', function () {
    $team = Team::factory()->create();

    $user = contactUserWithLevel(DataAccessLevel::Team, ['current_team_id' => $team->id]);
    $colleague = User::factory()->create(['current_team_id' => $team->id]);
    $outsider = User::factory()->create();

    Contact::factory()->ownedBy($user)->named('Mine', 'C')->create();
    Contact::factory()->ownedBy($colleague)->named('Colleague', 'C')->create();
    Contact::factory()->ownedBy($outsider)->named('Outsider', 'C')->create();

    expect(Contact::query()->visibleTo($user)->pluck('first_name')->sort()->values()->all())
        ->toBe(['Colleague', 'Mine']);
});

test('all access sees everything', function () {
    $user = contactUserWithLevel(DataAccessLevel::All);

    Contact::factory()->ownedBy($user)->create();
    Contact::factory()->create();
    Contact::factory()->create();

    expect(Contact::query()->visibleTo($user)->count())->toBe(3);
});

// -- Audit ---------------------------------------------------------------------

test('creating, updating and deleting a contact is audited', function () {
    $this->actingAs(User::factory()->create());

    $contact = Contact::factory()->create();
    $contact->update(['job_title' => 'CFO']);
    $contact->delete();

    expect(Activity::query()
        ->where('log_name', 'audit')
        ->where('subject_type', Contact::class)
        ->pluck('event')
        ->all())->toBe(['created', 'updated', 'deleted']);
});

test('the audit trail records only the allowlisted contact fields', function () {
    $this->actingAs(User::factory()->create());

    $contact = Contact::factory()->create();
    $contact->update(['description' => 'A private note', 'job_title' => 'CFO']);

    $entry = Activity::query()
        ->where('subject_type', Contact::class)
        ->where('event', 'updated')
        ->firstOrFail();

    expect(array_keys($entry->properties['attributes']))->toBe(['job_title'])
        ->and(json_encode($entry->properties))->not->toContain('A private note');
});
