<?php

use App\Domain\Accounts\Actions\CreateAccountAction;
use App\Domain\Accounts\Actions\DeleteAccountAction;
use App\Domain\Accounts\Actions\UpdateAccountAction;
use App\Domain\Accounts\DTOs\AccountData;
use App\Domain\Accounts\Enums\AccountSize;
use App\Domain\Accounts\Enums\Industry;
use App\Domain\Accounts\Models\Account;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Domain\Shared\UI\ChipPalette;
use App\Models\Team;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

// -- Enums ---------------------------------------------------------------------

test('every industry has a label, a colour and appears in the options', function (Industry $industry) {
    expect($industry->label())->not->toBeEmpty()
        ->and($industry->color())->not->toBeEmpty()
        ->and(Industry::options())->toHaveKey($industry->value);
})->with(Industry::cases());

test('industry options are alphabetical with Other last', function () {
    $labels = array_values(Industry::options());

    expect($labels[array_key_last($labels)])->toBe('Other');

    $withoutOther = array_slice($labels, 0, -1);
    $sorted = $withoutOther;
    sort($sorted);

    expect($withoutOther)->toBe($sorted);
});

test('every size has a label, a short label and a colour', function (AccountSize $size) {
    expect($size->label())->not->toBeEmpty()
        ->and($size->shortLabel())->not->toBeEmpty()
        ->and($size->color())->not->toBeEmpty();
})->with(AccountSize::cases());

test('every enum colour is one the chip palette knows', function () {
    foreach ([...Industry::cases(), ...AccountSize::cases()] as $case) {
        expect(ChipPalette::has($case->color()))
            ->toBeTrue("{$case->value} uses a colour the palette does not define");
    }
});

// -- The model -----------------------------------------------------------------

test('an account exposes its industry and size as enums', function () {
    $account = Account::factory()->industry(Industry::Technology)->size(AccountSize::Large)->create();

    expect($account->industry())->toBe(Industry::Technology)
        ->and($account->size())->toBe(AccountSize::Large);
});

test('an unrecognised industry reads as null rather than throwing', function () {
    $account = Account::factory()->create();
    $account->forceFill(['industry' => 'time-travel'])->save();

    expect($account->fresh()->industry())->toBeNull();
});

test('a website without a scheme still produces a usable link', function () {
    expect(Account::factory()->make(['website' => 'example.com'])->websiteUrl())->toBe('https://example.com')
        ->and(Account::factory()->make(['website' => 'http://example.com'])->websiteUrl())->toBe('http://example.com')
        ->and(Account::factory()->make(['website' => 'https://example.com'])->websiteUrl())->toBe('https://example.com')
        ->and(Account::factory()->make(['website' => null])->websiteUrl())->toBeNull()
        ->and(Account::factory()->make(['website' => '  '])->websiteUrl())->toBeNull();
});

test('revenue is stored as a decimal, not a float', function () {
    $account = Account::factory()->create(['annual_revenue' => '1234567.89']);

    expect($account->fresh()->annual_revenue)->toBe('1234567.89');
});

test('search matches name, legal name, email, phone and city', function () {
    Account::factory()->create(['name' => 'Acme Corporation', 'city' => 'Leeds']);
    Account::factory()->create(['name' => 'Beta Industries', 'legal_name' => 'Acme Holdings Ltd']);
    Account::factory()->create(['name' => 'Gamma Ltd', 'email' => 'hello@acme.test']);
    Account::factory()->create(['name' => 'Delta Ltd', 'city' => 'Bristol']);

    expect(Account::query()->search('acme')->count())->toBe(3)
        ->and(Account::query()->search('Bristol')->pluck('name')->all())->toBe(['Delta Ltd'])
        ->and(Account::query()->search('')->count())->toBe(4);
});

// -- Hierarchy -----------------------------------------------------------------

test('ancestors walk up to the root, nearest first', function () {
    $root = Account::factory()->create(['name' => 'Root']);
    $middle = Account::factory()->childOf($root)->create(['name' => 'Middle']);
    $leaf = Account::factory()->childOf($middle)->create(['name' => 'Leaf']);

    expect($leaf->ancestors()->pluck('name')->all())->toBe(['Middle', 'Root'])
        ->and($leaf->depth())->toBe(2)
        ->and($root->depth())->toBe(0)
        ->and($root->isRoot())->toBeTrue()
        ->and($leaf->isRoot())->toBeFalse();
});

test('descendants reach every level beneath', function () {
    $root = Account::factory()->create(['name' => 'Root']);
    $a = Account::factory()->childOf($root)->create(['name' => 'A']);
    Account::factory()->childOf($a)->create(['name' => 'A1']);
    Account::factory()->childOf($root)->create(['name' => 'B']);
    Account::factory()->create(['name' => 'Unrelated']);

    expect($root->descendants()->pluck('name')->sort()->values()->all())->toBe(['A', 'A1', 'B']);
});

test('a corrupted parent loop does not hang a hierarchy walk', function () {
    $first = Account::factory()->create(['name' => 'First']);
    $second = Account::factory()->childOf($first)->create(['name' => 'Second']);

    // Written straight to the column, bypassing the guards, to prove the walk
    // itself is safe rather than only the code that prevents loops.
    $first->forceFill(['parent_id' => $second->id])->save();

    expect($first->fresh()->ancestors()->pluck('name')->all())->toBe(['Second'])
        ->and($first->fresh()->descendants()->pluck('name')->all())->toBe(['Second']);
});

test('an account cannot be parented by itself or its own descendant', function () {
    $root = Account::factory()->create();
    $child = Account::factory()->childOf($root)->create();
    $grandchild = Account::factory()->childOf($child)->create();
    $unrelated = Account::factory()->create();

    expect($root->canBeParentedBy($root))->toBeFalse()
        ->and($root->canBeParentedBy($child))->toBeFalse()
        ->and($root->canBeParentedBy($grandchild))->toBeFalse()
        ->and($root->canBeParentedBy($unrelated))->toBeTrue()
        ->and($root->canBeParentedBy(null))->toBeTrue()
        ->and($child->canBeParentedBy($unrelated))->toBeTrue();
});

test('isDescendantOf reads the chain both ways', function () {
    $root = Account::factory()->create();
    $child = Account::factory()->childOf($root)->create();

    expect($child->isDescendantOf($root))->toBeTrue()
        ->and($root->isDescendantOf($child))->toBeFalse();
});

// -- Actions -------------------------------------------------------------------

test('creating an account defaults the owner to whoever created it', function () {
    $actor = User::factory()->create();

    $account = app(CreateAccountAction::class)(
        AccountData::fromArray(['name' => 'Acme']),
        $actor
    );

    expect($account->owner_id)->toBe($actor->id)
        ->and($account->name)->toBe('Acme');
});

test('an explicit owner wins over the actor', function () {
    $actor = User::factory()->create();
    $owner = User::factory()->create();

    $account = app(CreateAccountAction::class)(
        AccountData::fromArray(['name' => 'Acme', 'owner_id' => $owner->id]),
        $actor
    );

    expect($account->owner_id)->toBe($owner->id);
});

test('creating with a parent that does not exist is refused', function () {
    expect(fn () => app(CreateAccountAction::class)(
        AccountData::fromArray(['name' => 'Acme', 'parent_id' => 9999]),
        User::factory()->create()
    ))->toThrow(RuntimeException::class, 'does not exist');
});

test('an update clears a field the user emptied', function () {
    $account = Account::factory()->create(['name' => 'Acme', 'phone' => '0113 000 0000']);

    app(UpdateAccountAction::class)($account, AccountData::fromArray(['name' => 'Acme']));

    expect($account->fresh()->phone)->toBeNull();
});

test('an update that omits the owner leaves it alone', function () {
    $owner = User::factory()->create();
    $account = Account::factory()->ownedBy($owner)->create();

    app(UpdateAccountAction::class)($account, AccountData::fromArray(['name' => 'Renamed']));

    expect($account->fresh()->owner_id)->toBe($owner->id)
        ->and($account->fresh()->name)->toBe('Renamed');
});

test('an update refuses a parent that would create a loop', function () {
    $root = Account::factory()->create();
    $child = Account::factory()->childOf($root)->create();

    expect(fn () => app(UpdateAccountAction::class)(
        $root,
        AccountData::fromArray(['name' => $root->name, 'parent_id' => $child->id])
    ))->toThrow(RuntimeException::class, 'beneath itself');

    expect(fn () => app(UpdateAccountAction::class)(
        $root,
        AccountData::fromArray(['name' => $root->name, 'parent_id' => $root->id])
    ))->toThrow(RuntimeException::class, 'beneath itself');

    expect($root->fresh()->parent_id)->toBeNull();
});

test('deleting an account lifts its subsidiaries to the top level', function () {
    $root = Account::factory()->create();
    $child = Account::factory()->childOf($root)->create();

    app(DeleteAccountAction::class)($root);

    expect($root->fresh()->trashed())->toBeTrue()
        ->and($child->fresh()->parent_id)->toBeNull()
        ->and($child->fresh()->trashed())->toBeFalse();
});

// -- Access level --------------------------------------------------------------

/**
 * @param  array<string, mixed>  $attributes
 */
function accountUserWithLevel(DataAccessLevel $level, array $attributes = []): User
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

test('own access sees only the accounts they own', function () {
    $user = accountUserWithLevel(DataAccessLevel::Own);

    Account::factory()->ownedBy($user)->create(['name' => 'Mine']);
    Account::factory()->create(['name' => 'Someone else']);

    expect(Account::query()->visibleTo($user)->pluck('name')->all())->toBe(['Mine']);
});

test('team access sees the whole active team', function () {
    $team = Team::factory()->create();

    $user = accountUserWithLevel(DataAccessLevel::Team, ['current_team_id' => $team->id]);
    $colleague = User::factory()->create(['current_team_id' => $team->id]);
    $outsider = User::factory()->create();

    Account::factory()->ownedBy($user)->create(['name' => 'Mine']);
    Account::factory()->ownedBy($colleague)->create(['name' => 'Colleague']);
    Account::factory()->ownedBy($outsider)->create(['name' => 'Outsider']);

    expect(Account::query()->visibleTo($user)->pluck('name')->sort()->values()->all())
        ->toBe(['Colleague', 'Mine']);
});

test('all access sees everything', function () {
    $user = accountUserWithLevel(DataAccessLevel::All);

    Account::factory()->ownedBy($user)->create();
    Account::factory()->create();
    Account::factory()->create();

    expect(Account::query()->visibleTo($user)->count())->toBe(3);
});

test('a user with no role sees only their own', function () {
    $user = User::factory()->create();

    Account::factory()->ownedBy($user)->create(['name' => 'Mine']);
    Account::factory()->create(['name' => 'Theirs']);

    expect(Account::query()->visibleTo($user)->pluck('name')->all())->toBe(['Mine']);
});

// -- Audit ---------------------------------------------------------------------

test('creating, updating and deleting an account is audited', function () {
    $actor = User::factory()->create();
    $this->actingAs($actor);

    $account = Account::factory()->create(['name' => 'Acme']);
    $account->update(['name' => 'Acme Renamed']);
    $account->delete();

    $events = Activity::query()
        ->where('log_name', 'audit')
        ->where('subject_type', Account::class)
        ->pluck('event')
        ->all();

    expect($events)->toBe(['created', 'updated', 'deleted']);
});

test('the audit trail records only the allowlisted account fields', function () {
    $this->actingAs(User::factory()->create());

    $account = Account::factory()->create();
    $account->update(['description' => 'A private note', 'name' => 'Renamed']);

    $entry = Activity::query()->where('subject_type', Account::class)->where('event', 'updated')->firstOrFail();

    expect(array_keys($entry->properties['attributes']))->toBe(['name'])
        ->and(json_encode($entry->properties))->not->toContain('A private note');
});
