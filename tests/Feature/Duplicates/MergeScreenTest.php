<?php

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\PermissionResolver;
use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Duplicates\DuplicateRegistry;
use App\Livewire\Accounts\AccountForm;
use App\Livewire\Duplicates\MergeRecords;
use App\Livewire\Leads\LeadForm;
use App\Livewire\Leads\LeadShow;
use App\Models\User;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function mergeUser(array $permissions = ['leads.view', 'leads.merge']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

/**
 * Two leads that match on email, owned by the given user.
 *
 * @return array{0: Lead, 1: Lead}
 */
function duplicatePair(User $owner): array
{
    return [
        Lead::factory()->ownedBy($owner)->named('Dara', 'Okafor')
            ->create(['email' => 'dara@acme.test', 'city' => 'Bristol', 'job_title' => null]),
        Lead::factory()->ownedBy($owner)->named('D', 'Okafor')
            ->create(['email' => 'dara@acme.test', 'city' => 'Leeds', 'job_title' => 'Head of Operations']),
    ];
}

function mergeUrl(string $module, int $id): string
{
    return route('duplicates.merge', ['module' => $module, 'record' => $id]);
}

// -- Access --------------------------------------------------------------------

test('a guest is sent to sign in', function () {
    $lead = Lead::factory()->create();

    $this->get(mergeUrl('leads', $lead->id))->assertRedirect(route('login'));
});

test('merging needs its own permission, not just the right to edit', function () {
    $user = mergeUser(['leads.view', 'leads.update', 'leads.delete']);
    $lead = Lead::factory()->ownedBy($user)->create();

    $this->actingAs($user)->get(mergeUrl('leads', $lead->id))->assertForbidden();
});

test('somebody with the permission gets the screen', function () {
    $user = mergeUser();
    $lead = Lead::factory()->ownedBy($user)->create();

    $this->actingAs($user)->get(mergeUrl('leads', $lead->id))->assertOk();
});

test('the merge permissions are in the catalogue, so the roles matrix can grant them', function (string $module) {
    expect(PermissionCatalogue::has($module.'.merge'))->toBeTrue();
})->with(DuplicateRegistry::keys());

test('a module the registry does not list cannot be reached', function (string $module) {
    $user = mergeUser(['leads.view', 'leads.merge', 'users.view']);
    $lead = Lead::factory()->ownedBy($user)->create();

    $this->actingAs($user)
        ->get('/duplicates/'.$module.'/'.$lead->id.'/merge')
        ->assertNotFound();
})->with(['users', 'settings', 'App%5CModels%5CUser']);

test('a record the viewer cannot see is not there', function () {
    $user = mergeUser();
    $theirs = Lead::factory()->create();

    // Owned by somebody else and this user has no team or all access level.
    $this->actingAs($user)->get(mergeUrl('leads', $theirs->id))->assertNotFound();
});

test('all three modules resolve and render', function (string $module) {
    $user = mergeUser([$module.'.view', $module.'.merge']);

    $record = match ($module) {
        'leads' => Lead::factory()->ownedBy($user)->create(),
        'contacts' => Contact::factory()->ownedBy($user)->create(),
        'accounts' => Account::factory()->ownedBy($user)->create(),
    };

    $this->actingAs($user)->get(mergeUrl($module, $record->id))->assertOk();
})->with(DuplicateRegistry::keys());

// -- Choosing a counterpart -----------------------------------------------------

test('the screen lists the candidates with why each one matched', function () {
    $user = mergeUser();
    [$record, $other] = duplicatePair($user);

    Livewire::actingAs($user)
        ->test(MergeRecords::class, ['module' => 'leads', 'record' => $record->id])
        ->assertSee('D Okafor')
        ->assertSee('Email address matches')
        ->assertSee('Exact');
});

test('with nothing matching it says so rather than showing an empty form', function () {
    $user = mergeUser();
    $lead = Lead::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(MergeRecords::class, ['module' => 'leads', 'record' => $lead->id])
        ->assertSee('No duplicates found');
});

test('only a record the finder offered can be chosen', function () {
    $user = mergeUser();
    [$record, $other] = duplicatePair($user);
    $unrelated = Lead::factory()->ownedBy($user)->create(['email' => 'someone@else.test']);

    $component = Livewire::actingAs($user)
        ->test(MergeRecords::class, ['module' => 'leads', 'record' => $record->id])
        ->call('selectCounterpart', $other->id)
        ->assertSet('otherId', $other->id);

    // Editing the query string to name a record that is not a candidate.
    $component->call('selectCounterpart', $unrelated->id)->assertSet('otherId', null);
});

test('either record can be the one that is kept', function () {
    $user = mergeUser();
    [$record, $other] = duplicatePair($user);

    $component = Livewire::actingAs($user)
        ->test(MergeRecords::class, ['module' => 'leads', 'record' => $record->id])
        ->call('selectCounterpart', $other->id);

    expect($component->instance()->survivor()->id)->toBe($record->id)
        ->and($component->instance()->loser()->id)->toBe($other->id);

    $component->set('keep', 'other');

    expect($component->instance()->survivor()->id)->toBe($other->id)
        ->and($component->instance()->loser()->id)->toBe($record->id);
});

// -- Choosing values -------------------------------------------------------------

test('the survivor blanks are filled from the other record, and its values kept', function () {
    $user = mergeUser();
    [$record, $other] = duplicatePair($user);

    $component = Livewire::actingAs($user)
        ->test(MergeRecords::class, ['module' => 'leads', 'record' => $record->id])
        ->call('selectCounterpart', $other->id);

    $chosen = $component->get('chosen');

    // The survivor has no job title and the other does, so take it; both have a
    // city, so the survivor keeps its own.
    expect($chosen['job_title'])->toBe('other')
        ->and($chosen['city'])->toBe('this')
        ->and($component->instance()->chosenValues())
        ->toBe(['job_title' => 'Head of Operations']);
});

test('only the fields the two disagree on are put to the operator', function () {
    $user = mergeUser();
    [$record, $other] = duplicatePair($user);

    $component = Livewire::actingAs($user)
        ->test(MergeRecords::class, ['module' => 'leads', 'record' => $record->id])
        ->call('selectCounterpart', $other->id);

    $conflicts = $component->instance()->conflictingFields();

    expect($conflicts)->toContain('city')
        ->and($conflicts)->toContain('job_title')
        ->and($conflicts)->not->toContain('email');
});

test('a field the module does not offer is ignored however it arrives', function () {
    $user = mergeUser();
    [$record, $other] = duplicatePair($user);

    $component = Livewire::actingAs($user)
        ->test(MergeRecords::class, ['module' => 'leads', 'record' => $record->id])
        ->call('selectCounterpart', $other->id)
        ->call('chooseField', 'status', 'other')
        ->call('chooseField', 'merged_into_id', 'other');

    expect($component->get('chosen'))->not->toHaveKey('status')
        ->and($component->get('chosen'))->not->toHaveKey('merged_into_id');
});

test('everything can be taken from one side at once', function () {
    $user = mergeUser();
    [$record, $other] = duplicatePair($user);

    $component = Livewire::actingAs($user)
        ->test(MergeRecords::class, ['module' => 'leads', 'record' => $record->id])
        ->call('selectCounterpart', $other->id)
        ->call('takeAllFrom', 'other');

    expect($component->instance()->chosenValues())->toHaveKey('city')
        ->and($component->instance()->chosenValues()['city'])->toBe('Leeds');
});

// -- Merging ---------------------------------------------------------------------

test('merging folds the records together and goes back to the survivor', function () {
    $user = mergeUser();
    [$record, $other] = duplicatePair($user);

    Livewire::actingAs($user)
        ->test(MergeRecords::class, ['module' => 'leads', 'record' => $record->id])
        ->call('selectCounterpart', $other->id)
        ->call('merge')
        ->assertRedirect(route('leads.show', $record));

    expect($record->fresh()->job_title)->toBe('Head of Operations')
        ->and(Lead::withTrashed()->find($other->id)->merged_into_id)->toBe($record->id);
});

test('merging without choosing a counterpart says so instead of failing', function () {
    $user = mergeUser();
    [$record] = duplicatePair($user);

    Livewire::actingAs($user)
        ->test(MergeRecords::class, ['module' => 'leads', 'record' => $record->id])
        ->call('merge')
        ->assertDispatched('notify', type: 'error')
        ->assertNoRedirect();
});

test('the permission is checked against both records, not just the one on screen', function () {
    $user = mergeUser();
    [$record, $other] = duplicatePair($user);

    // Kept visible to the finder but no longer this user's to merge.
    $component = Livewire::actingAs($user)
        ->test(MergeRecords::class, ['module' => 'leads', 'record' => $record->id])
        ->call('selectCounterpart', $other->id);

    $user->revokePermissionTo('leads.merge');

    $component->call('merge')->assertForbidden();

    expect(Lead::withTrashed()->find($other->id)->merged_into_id)->toBeNull();
});

test('a merge refused by the action is reported rather than thrown at the browser', function () {
    $user = mergeUser();
    [$record, $other] = duplicatePair($user);

    $component = Livewire::actingAs($user)
        ->test(MergeRecords::class, ['module' => 'leads', 'record' => $record->id])
        ->call('selectCounterpart', $other->id);

    // Merged from somewhere else in between.
    $third = Lead::factory()->ownedBy($user)->create();
    $other->forceFill(['merged_into_id' => $third->id, 'merged_at' => now()])->save();

    $component->call('merge')->assertDispatched('notify', type: 'error');
});

// -- The screen follows the UI standard -------------------------------------------

test('the merge screen has no plain dropdown', function () {
    $user = mergeUser();
    [$record, $other] = duplicatePair($user);

    $html = Livewire::actingAs($user)
        ->test(MergeRecords::class, ['module' => 'leads', 'record' => $record->id])
        ->call('selectCounterpart', $other->id)
        ->html();

    expect(substr_count($html, '<select'))->toBe(substr_count($html, 'tomSelectField('));
});

test('foreign keys are compared by name, not by number', function () {
    $user = mergeUser(['contacts.view', 'contacts.merge']);
    $account = Account::factory()->ownedBy($user)->create(['name' => 'Acme Industries']);

    $record = Contact::factory()->ownedBy($user)->create(['email' => 'dana@fbi.test', 'account_id' => null]);
    $other = Contact::factory()->ownedBy($user)->create(['email' => 'dana@fbi.test', 'account_id' => $account->id]);

    Livewire::actingAs($user)
        ->test(MergeRecords::class, ['module' => 'contacts', 'record' => $record->id])
        ->call('selectCounterpart', $other->id)
        // The comparison shows the account's name, not its id.
        ->assertSee('Acme Industries')
        ->assertDontSee('Account</th>'.$account->id, false);
});

// -- The banner on a record's own page ---------------------------------------------

test('a record with a duplicate says so, with a way to deal with it', function () {
    $user = mergeUser();
    [$record, $other] = duplicatePair($user);

    Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => $record])
        ->assertSee('1 possible duplicate')
        ->assertSee('Review and merge')
        ->assertSee(mergeUrl('leads', $record->id), false);
});

test('somebody who cannot merge is not offered the link', function () {
    $user = mergeUser(['leads.view']);
    [$record, $other] = duplicatePair($user);

    Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => $record])
        ->assertSee('1 possible duplicate')
        ->assertDontSee('Review and merge');
});

test('a record with no duplicates says nothing about them', function () {
    $user = mergeUser();
    $lead = Lead::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => $lead])
        ->assertDontSee('possible duplicate');
});

test('a merged record says where it went instead of warning again', function () {
    $user = mergeUser();
    [$record, $other] = duplicatePair($user);

    Livewire::actingAs($user)
        ->test(MergeRecords::class, ['module' => 'leads', 'record' => $record->id])
        ->call('selectCounterpart', $other->id)
        ->call('merge');

    Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => Lead::withTrashed()->find($other->id)])
        ->assertSee('was merged into')
        ->assertDontSee('possible duplicate');
});

test('a merged record is still reachable at its own address', function () {
    $user = mergeUser();
    [$record, $other] = duplicatePair($user);

    Livewire::actingAs($user)
        ->test(MergeRecords::class, ['module' => 'leads', 'record' => $record->id])
        ->call('selectCounterpart', $other->id)
        ->call('merge');

    // Route-model binding refuses a soft-deleted record before the component
    // ever runs, so this has to go through the real URL to mean anything.
    $this->actingAs($user)
        ->get(route('leads.show', $other->id))
        ->assertOk()
        ->assertSee('was merged into');
});

test('a record that was simply removed is still gone', function (string $module) {
    $user = mergeUser([$module.'.view', $module.'.merge']);

    $record = match ($module) {
        'leads' => Lead::factory()->ownedBy($user)->create(),
        'contacts' => Contact::factory()->ownedBy($user)->create(),
        'accounts' => Account::factory()->ownedBy($user)->create(),
    };

    $record->delete();

    // withTrashed on the route is for merged records only; an ordinary
    // deletion must not become readable as a side effect.
    $this->actingAs($user)->get(route($module.'.show', $record->id))->assertNotFound();
})->with(DuplicateRegistry::keys());

// -- The warning while typing --------------------------------------------------------

test('capturing something that already exists warns before it is saved', function () {
    $user = mergeUser(['leads.view', 'leads.create', 'leads.merge']);
    Lead::factory()->ownedBy($user)->named('Dara', 'Okafor')->create(['email' => 'dara@acme.test']);

    Livewire::actingAs($user)
        ->test(LeadForm::class)
        ->assertDontSee('This may already exist')
        ->set('email', 'DARA@acme.test')
        ->assertSee('This may already exist')
        ->assertSee('Dara Okafor');
});

test('editing a record does not warn about the record being edited', function () {
    $user = mergeUser(['leads.view', 'leads.update', 'leads.merge']);
    $lead = Lead::factory()->ownedBy($user)->create(['email' => 'dara@acme.test']);

    Livewire::actingAs($user)
        ->test(LeadForm::class, ['lead' => $lead])
        ->assertDontSee('This may already exist');
});

test('the warning is informative, never blocking', function () {
    $user = mergeUser(['leads.view', 'leads.create', 'leads.merge']);
    Lead::factory()->ownedBy($user)->create(['email' => 'dara@acme.test']);

    // A near-duplicate can be genuine, and only the person entering it knows.
    Livewire::actingAs($user)
        ->test(LeadForm::class)
        ->set('first_name', 'Dara')
        ->set('last_name', 'Okafor')
        ->set('email', 'dara@acme.test')
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::query()->where('email', 'dara@acme.test')->count())->toBe(2);
});

test('an account form warns on a name that only differs by its legal suffix', function () {
    $user = mergeUser(['accounts.view', 'accounts.create', 'accounts.merge']);
    Account::factory()->ownedBy($user)->create(['name' => 'Acme Industries Ltd']);

    Livewire::actingAs($user)
        ->test(AccountForm::class)
        ->set('name', 'Acme Industries')
        ->assertSee('This may already exist')
        ->assertSee('Acme Industries Ltd');
});
