<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Leads\Actions\UpdateLeadAction;
use App\Domain\Leads\DTOs\LeadData;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Livewire\Leads\LeadConvert;
use App\Livewire\Leads\LeadForm;
use App\Models\User;
use Livewire\Livewire;

/**
 * A lead can be linked to an account and a person already on file, and
 * conversion then joins them instead of creating second copies.
 */
function linkingUser(): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models([
        'leads.view', 'leads.create', 'leads.update', 'leads.convert',
        'accounts.view', 'accounts.create', 'contacts.view', 'contacts.create',
    ]) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

// -- The lead form -------------------------------------------------------------

test('a lead can be linked to an account and a contact on file', function () {
    $user = linkingUser();
    $account = Account::factory()->ownedBy($user)->create(['name' => 'Globex']);
    $contact = Contact::factory()->ownedBy($user)->named('Hank', 'Scorpio')->create(['account_id' => $account->id]);

    Livewire::actingAs($user)
        ->test(LeadForm::class)
        ->set('account_id', (string) $account->id)
        ->assertSet('company_name', 'Globex')
        ->set('contact_id', (string) $contact->id)
        ->set('email', 'hank@globex.test')
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->firstOrFail();

    expect($lead->account_id)->toBe($account->id)
        ->and($lead->contact_id)->toBe($contact->id)
        ->and($lead->fullName())->toBe('Hank Scorpio');
});

test('the new account name is asked for only while no account is linked', function () {
    $user = linkingUser();
    $account = Account::factory()->ownedBy($user)->create(['name' => 'Globex']);

    Livewire::actingAs($user)
        ->test(LeadForm::class)
        ->assertSee('New account name')
        ->set('account_id', (string) $account->id)
        ->assertDontSee('New account name')
        ->set('account_id', null)
        ->assertSee('New account name');
});

test('a new account name and a new person name create both on save, linked to the lead', function () {
    $user = linkingUser();

    Livewire::actingAs($user)
        ->test(LeadForm::class)
        ->set('first_name', 'Dana')
        ->set('last_name', 'Scully')
        ->set('email', 'dana@fbi.test')
        ->set('new_account_name', 'FBI')
        ->set('new_contact_first_name', 'Dana')
        ->set('new_contact_last_name', 'Scully')
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->sole();
    $account = Account::query()->sole();
    $contact = Contact::query()->sole();

    expect($account->name)->toBe('FBI')
        ->and($contact->fullName())->toBe('Dana Scully')
        ->and($contact->email)->toBe('dana@fbi.test')
        ->and($contact->account_id)->toBe($account->id)
        ->and($lead->account_id)->toBe($account->id)
        ->and($lead->contact_id)->toBe($contact->id)
        ->and($lead->company_name)->toBe('FBI');
});

test('left blank, no account or contact is created', function () {
    Livewire::actingAs(linkingUser())
        ->test(LeadForm::class)
        ->set('first_name', 'Dana')
        ->set('last_name', 'Scully')
        ->set('email', 'dana@fbi.test')
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::query()->count())->toBe(1)
        ->and(Account::query()->count())->toBe(0)
        ->and(Contact::query()->count())->toBe(0);
});

test('a new person needs both names', function () {
    Livewire::actingAs(linkingUser())
        ->test(LeadForm::class)
        ->set('first_name', 'Dana')
        ->set('last_name', 'Scully')
        ->set('email', 'dana@fbi.test')
        ->set('new_contact_first_name', 'Dana')
        ->call('save')
        ->assertHasErrors('new_contact_last_name');

    expect(Lead::query()->count())->toBe(0);
});

test('without permission to create accounts the new account name is neither shown nor honoured', function () {
    $user = User::factory()->create();

    foreach (PermissionResolver::models(['leads.view', 'leads.create']) as $permission) {
        $user->givePermissionTo($permission);
    }

    Livewire::actingAs($user->fresh())
        ->test(LeadForm::class)
        ->assertDontSee('New account name')
        ->set('first_name', 'Dana')
        ->set('last_name', 'Scully')
        ->set('email', 'dana@fbi.test')
        ->set('new_account_name', 'FBI')
        ->call('save')
        ->assertHasErrors('new_account_name');

    expect(Account::query()->count())->toBe(0);
});

test('picking a contact fills in the person and their account', function () {
    $user = linkingUser();
    $account = Account::factory()->ownedBy($user)->create(['name' => 'Globex']);
    $contact = Contact::factory()->ownedBy($user)->named('Hank', 'Scorpio')
        ->create(['account_id' => $account->id, 'email' => 'hank@globex.test']);

    Livewire::actingAs($user)
        ->test(LeadForm::class)
        ->set('contact_id', (string) $contact->id)
        ->assertSet('first_name', 'Hank')
        ->assertSet('last_name', 'Scorpio')
        ->assertSet('email', 'hank@globex.test')
        ->assertSet('account_id', (string) $account->id)
        ->assertSet('company_name', 'Globex');
});

test('the pickers search everything the viewer can see, and nothing else', function () {
    $user = linkingUser();
    $mine = Account::factory()->ownedBy($user)->create(['name' => 'Globex']);
    Account::factory()->create(['name' => 'Globex Rival']);

    $component = Livewire::actingAs($user)->test(LeadForm::class);

    expect(collect($component->instance()->searchAccounts('Globex')['options'])->pluck('value')->all())
        ->toBe([(string) $mine->id]);
});

test('an account the viewer cannot see is refused on save', function () {
    $user = linkingUser();
    $hidden = Account::factory()->create();

    Livewire::actingAs($user)
        ->test(LeadForm::class)
        ->set('first_name', 'Dana')
        ->set('last_name', 'Scully')
        ->set('email', 'dana@example.test')
        ->set('account_id', (string) $hidden->id)
        ->call('save')
        ->assertHasErrors('account_id');

    expect(Lead::query()->count())->toBe(0);
});

test('an update that does not mention the links leaves them alone', function () {
    $user = linkingUser();
    $account = Account::factory()->ownedBy($user)->create();
    $lead = Lead::factory()->ownedBy($user)->create(['account_id' => $account->id]);

    app(UpdateLeadAction::class)($lead, LeadData::fromArray([
        'first_name' => 'Renamed',
        'last_name' => 'Lead',
        'email' => 'renamed@example.test',
    ]));

    expect($lead->refresh()->account_id)->toBe($account->id);
});

// -- The convert page ----------------------------------------------------------

test('the convert page starts on the linked account and contact, and joins them', function () {
    $user = linkingUser();
    $account = Account::factory()->ownedBy($user)->create(['name' => 'Globex']);
    $contact = Contact::factory()->ownedBy($user)->create(['account_id' => $account->id]);
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::Qualified)
        ->create(['account_id' => $account->id, 'contact_id' => $contact->id]);

    $component = Livewire::actingAs($user)
        ->test(LeadConvert::class, ['lead' => $lead])
        ->assertSet('accountId', (string) $account->id)
        ->assertSet('contactId', (string) $contact->id);

    expect(collect($component->viewData('accounts'))->pluck('value')->all())->toContain((string) $account->id)
        ->and(collect($component->viewData('contacts'))->pluck('value')->all())->toContain((string) $contact->id);

    $component->call('convert')->assertHasNoErrors();

    expect(Account::query()->count())->toBe(1)
        ->and(Contact::query()->count())->toBe(1)
        ->and($lead->refresh()->converted_account_id)->toBe($account->id)
        ->and($lead->converted_contact_id)->toBe($contact->id);
});

test('the new person name is prefilled from the lead and can be changed', function () {
    $user = linkingUser();
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::Qualified)->named('dara', 'o')->create();

    Livewire::actingAs($user)
        ->test(LeadConvert::class, ['lead' => $lead])
        ->assertSet('contactFirstName', 'dara')
        ->assertSet('contactLastName', 'o')
        ->set('contactFirstName', 'Dara')
        ->set('contactLastName', 'Okafor')
        ->call('convert')
        ->assertHasNoErrors();

    expect(Contact::query()->sole()->fullName())->toBe('Dara Okafor');
});

test('a new person needs a name', function () {
    $user = linkingUser();
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::Qualified)->create();

    Livewire::actingAs($user)
        ->test(LeadConvert::class, ['lead' => $lead])
        ->set('contactFirstName', '')
        ->call('convert')
        ->assertHasErrors('contactFirstName');

    expect(Contact::query()->count())->toBe(0);
});
