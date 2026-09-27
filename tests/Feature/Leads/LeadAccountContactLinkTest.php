<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Models\Deal;
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

test('a lead can be linked to an account and several contacts on file', function () {
    $user = linkingUser();
    $account = Account::factory()->ownedBy($user)->create(['name' => 'Globex']);
    $hank = Contact::factory()->ownedBy($user)->named('Hank', 'Scorpio')->create(['account_id' => $account->id]);
    $frank = Contact::factory()->ownedBy($user)->named('Frank', 'Grimes')->create(['account_id' => $account->id]);

    Livewire::actingAs($user)
        ->test(LeadForm::class)
        ->set('first_name', 'Homer')
        ->set('last_name', 'Simpson')
        ->set('email', 'homer@example.test')
        ->set('account_id', (string) $account->id)
        ->set('contacts.0.contact_id', (string) $hank->id)
        ->call('addContactRow')
        ->set('contacts.1.contact_id', (string) $frank->id)
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->sole();

    expect($lead->account_id)->toBe($account->id)
        ->and($lead->contacts->pluck('id')->all())->toBe([$hank->id, $frank->id])
        // The lead's own details are its own.
        ->and($lead->fullName())->toBe('Homer Simpson');
});

test('the new account fields show only while no account is linked', function () {
    $user = linkingUser();
    $account = Account::factory()->ownedBy($user)->create(['name' => 'Globex']);

    Livewire::actingAs($user)
        ->test(LeadForm::class)
        ->assertSee('New account')
        ->set('account_id', (string) $account->id)
        ->assertDontSee('New account')
        ->set('account_id', null)
        ->assertSee('New account');
});

test('new accounts and people are created from their own fields, not the lead\'s', function () {
    $user = linkingUser();

    Livewire::actingAs($user)
        ->test(LeadForm::class)
        ->set('first_name', 'Dana')
        ->set('last_name', 'Scully')
        ->set('email', 'lead@example.test')
        ->set('phone', '0100')
        ->set('website', 'lead.example')
        ->set('city', 'Dhaka')
        ->set('new_account_name', 'FBI')
        ->set('new_account_email', 'office@fbi.test')
        ->set('new_account_phone', '0200')
        ->set('new_account_website', 'fbi.test')
        ->set('contacts.0.first_name', 'Fox')
        ->set('contacts.0.last_name', 'Mulder')
        ->set('contacts.0.job_title', 'Agent')
        ->set('contacts.0.email', 'fox@fbi.test')
        ->set('contacts.0.address_line_1', '2630 Hegal Place')
        ->set('contacts.0.address_line_2', 'Apt 42')
        ->set('contacts.0.city', 'Alexandria')
        ->set('contacts.0.state', 'Virginia')
        ->set('contacts.0.postal_code', '23242')
        ->set('contacts.0.country', 'USA')
        ->call('addContactRow')
        ->set('contacts.1.first_name', 'Walter')
        ->set('contacts.1.last_name', 'Skinner')
        ->set('contacts.1.mobile', '0300')
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->sole();
    $account = Account::query()->sole();
    $fox = Contact::query()->where('first_name', 'Fox')->sole();
    $walter = Contact::query()->where('first_name', 'Walter')->sole();

    expect($account->name)->toBe('FBI')
        ->and($account->email)->toBe('office@fbi.test')
        ->and($account->phone)->toBe('0200')
        ->and($account->website)->toBe('fbi.test')
        ->and($account->city)->toBeNull()
        ->and($fox->email)->toBe('fox@fbi.test')
        ->and($fox->job_title)->toBe('Agent')
        ->and($fox->phone)->toBeNull()
        ->and($fox->address_line_1)->toBe('2630 Hegal Place')
        ->and($fox->address_line_2)->toBe('Apt 42')
        ->and($fox->city)->toBe('Alexandria')
        ->and($fox->state)->toBe('Virginia')
        ->and($fox->postal_code)->toBe('23242')
        ->and($fox->country)->toBe('USA')
        // The lead's own city is not copied in.
        ->and($walter->city)->toBeNull()
        ->and($walter->mobile)->toBe('0300')
        ->and($walter->email)->toBeNull()
        ->and($fox->account_id)->toBe($account->id)
        ->and($walter->account_id)->toBe($account->id)
        ->and($lead->account_id)->toBe($account->id)
        ->and($lead->contacts->pluck('id')->all())->toBe([$fox->id, $walter->id])
        ->and($lead->company_name)->toBeNull();
});

test('left blank, no account or contact is created', function () {
    Livewire::actingAs(linkingUser())
        ->test(LeadForm::class)
        ->set('first_name', 'Dana')
        ->set('last_name', 'Scully')
        ->set('email', 'dana@fbi.test')
        ->call('addContactRow')
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::query()->count())->toBe(1)
        ->and(Account::query()->count())->toBe(0)
        ->and(Contact::query()->count())->toBe(0);
});

test('a new person needs both names, and a new account a name', function () {
    Livewire::actingAs(linkingUser())
        ->test(LeadForm::class)
        ->set('first_name', 'Dana')
        ->set('last_name', 'Scully')
        ->set('email', 'dana@fbi.test')
        ->set('contacts.0.first_name', 'Fox')
        ->call('save')
        ->assertHasErrors('contacts.0.last_name');

    Livewire::actingAs(linkingUser())
        ->test(LeadForm::class)
        ->set('first_name', 'Dana')
        ->set('last_name', 'Scully')
        ->set('email', 'dana@fbi.test')
        ->set('new_account_email', 'office@fbi.test')
        ->call('save')
        ->assertHasErrors('new_account_name');

    expect(Lead::query()->count())->toBe(0);
});

test('without permission to create accounts the new account fields are neither shown nor honoured', function () {
    $user = User::factory()->create();

    foreach (PermissionResolver::models(['leads.view', 'leads.create']) as $permission) {
        $user->givePermissionTo($permission);
    }

    Livewire::actingAs($user->fresh())
        ->test(LeadForm::class)
        ->assertDontSee('New account')
        ->assertDontSee('New person')
        ->set('first_name', 'Dana')
        ->set('last_name', 'Scully')
        ->set('email', 'dana@fbi.test')
        ->set('new_account_name', 'FBI')
        ->call('save')
        ->assertHasErrors('new_account_name');

    expect(Account::query()->count())->toBe(0);
});

test('picking a contact links their account but leaves the lead\'s details alone', function () {
    $user = linkingUser();
    $account = Account::factory()->ownedBy($user)->create(['name' => 'Globex']);
    $contact = Contact::factory()->ownedBy($user)->named('Hank', 'Scorpio')
        ->create(['account_id' => $account->id, 'email' => 'hank@globex.test']);

    Livewire::actingAs($user)
        ->test(LeadForm::class)
        ->set('contacts.0.contact_id', (string) $contact->id)
        ->assertSet('account_id', (string) $account->id)
        ->assertSet('first_name', '')
        ->assertSet('email', null);
});

test('removing people and saving unlinks them', function () {
    $user = linkingUser();
    $hank = Contact::factory()->ownedBy($user)->create();
    $frank = Contact::factory()->ownedBy($user)->create();
    $lead = Lead::factory()->ownedBy($user)->create(['email' => 'lead@example.test']);
    $lead->contacts()->attach([$hank->id => ['position' => 0], $frank->id => ['position' => 1]]);

    Livewire::actingAs($user)
        ->test(LeadForm::class, ['lead' => $lead])
        ->assertSet('contacts.0.contact_id', (string) $hank->id)
        ->assertSet('contacts.1.contact_id', (string) $frank->id)
        ->call('removeContactRow', 0)
        ->call('save')
        ->assertHasNoErrors();

    expect($lead->refresh()->contacts->pluck('id')->all())->toBe([$frank->id]);
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
    $contact = Contact::factory()->ownedBy($user)->create();
    $lead = Lead::factory()->ownedBy($user)->create(['account_id' => $account->id]);
    $lead->contacts()->attach($contact->id, ['position' => 0]);

    app(UpdateLeadAction::class)($lead, LeadData::fromArray([
        'first_name' => 'Renamed',
        'last_name' => 'Lead',
        'email' => 'renamed@example.test',
    ]));

    expect($lead->refresh()->account_id)->toBe($account->id)
        ->and($lead->contacts->pluck('id')->all())->toBe([$contact->id]);
});

// -- The convert page ----------------------------------------------------------

test('the convert page starts on the linked account and contacts, and joins them', function () {
    $user = linkingUser();
    $account = Account::factory()->ownedBy($user)->create(['name' => 'Globex']);
    $hank = Contact::factory()->ownedBy($user)->create(['account_id' => $account->id]);
    $frank = Contact::factory()->ownedBy($user)->create(['account_id' => $account->id]);
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::Qualified)
        ->create(['account_id' => $account->id]);
    $lead->contacts()->attach([$hank->id => ['position' => 0], $frank->id => ['position' => 1]]);

    $component = Livewire::actingAs($user)
        ->test(LeadConvert::class, ['lead' => $lead])
        ->assertSet('account_id', (string) $account->id)
        ->assertSet('contacts.0.contact_id', (string) $hank->id)
        ->assertSet('contacts.1.contact_id', (string) $frank->id);

    $options = $component->instance()->peopleOptions();

    expect(collect($options['accounts'])->pluck('value')->all())->toContain((string) $account->id)
        ->and(collect($options['contacts'][0])->pluck('value')->all())->toContain((string) $hank->id);

    $component->call('convert')->assertHasNoErrors();

    expect(Account::query()->count())->toBe(1)
        ->and(Contact::query()->count())->toBe(2)
        ->and($lead->refresh()->converted_account_id)->toBe($account->id)
        ->and($lead->converted_contact_id)->toBe($hank->id)
        ->and(Deal::query()->sole()->contact_id)->toBe($hank->id);
});

test('the new account and person fields start from the lead, and what is typed is what is created', function () {
    $user = linkingUser();
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::Qualified)->named('dara', 'o')
        ->create(['company_name' => 'Acme', 'email' => 'dara@acme.test', 'city' => 'Dhaka']);

    Livewire::actingAs($user)
        ->test(LeadConvert::class, ['lead' => $lead])
        ->assertSet('new_account_name', 'Acme')
        ->assertSet('new_account_email', 'dara@acme.test')
        ->assertSet('contacts.0.first_name', 'dara')
        ->assertSet('contacts.0.city', 'Dhaka')
        ->set('new_account_name', 'Acme Industries')
        ->set('new_account_email', 'office@acme.test')
        ->set('contacts.0.first_name', 'Dara')
        ->set('contacts.0.last_name', 'Okafor')
        ->set('contacts.0.city', 'Chattogram')
        ->call('addContactRow')
        ->set('contacts.1.first_name', 'Ada')
        ->set('contacts.1.last_name', 'Obi')
        ->set('contacts.1.job_title', 'Buyer')
        ->call('convert')
        ->assertHasNoErrors();

    $account = Account::query()->sole();
    $dara = Contact::query()->where('first_name', 'Dara')->sole();
    $ada = Contact::query()->where('first_name', 'Ada')->sole();

    expect($account->name)->toBe('Acme Industries')
        ->and($account->email)->toBe('office@acme.test')
        // Only what was typed for it: not the lead's city.
        ->and($account->city)->toBeNull()
        ->and($dara->fullName())->toBe('Dara Okafor')
        ->and($dara->city)->toBe('Chattogram')
        ->and($ada->job_title)->toBe('Buyer')
        ->and($ada->email)->toBeNull()
        ->and($dara->account_id)->toBe($account->id)
        ->and($ada->account_id)->toBe($account->id)
        ->and($lead->refresh()->converted_contact_id)->toBe($dara->id)
        ->and($lead->contacts->pluck('id')->all())->toBe([$dara->id, $ada->id]);
});

test('the lead\'s other people join the account it converts into', function () {
    $user = linkingUser();
    $first = Contact::factory()->ownedBy($user)->create(['account_id' => null]);
    $second = Contact::factory()->ownedBy($user)->create(['account_id' => null]);
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::Qualified)->create();
    $lead->contacts()->attach([$first->id => ['position' => 0], $second->id => ['position' => 1]]);

    Livewire::actingAs($user)
        ->test(LeadConvert::class, ['lead' => $lead])
        ->call('removeContactRow', 1)
        ->call('convert')
        ->assertHasNoErrors();

    $account = Account::query()->sole();

    expect($first->refresh()->account_id)->toBe($account->id)
        ->and($second->refresh()->account_id)->toBe($account->id);
});

test('conversion needs a person and an account name', function () {
    $user = linkingUser();
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::Qualified)->create();

    Livewire::actingAs($user)
        ->test(LeadConvert::class, ['lead' => $lead])
        ->call('removeContactRow', 0)
        ->call('convert')
        ->assertHasErrors('contacts.0.first_name');

    Livewire::actingAs($user)
        ->test(LeadConvert::class, ['lead' => $lead])
        ->set('contacts.0.last_name', '')
        ->call('convert')
        ->assertHasErrors('contacts.0.last_name');

    expect(Contact::query()->count())->toBe(0)
        ->and($lead->refresh()->isConverted())->toBeFalse();
});

test('converting needs only the convert permission, not account or contact creation', function () {
    $user = User::factory()->create();

    foreach (PermissionResolver::models(['leads.view', 'leads.convert']) as $permission) {
        $user->givePermissionTo($permission);
    }

    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::Qualified)->create(['company_name' => 'Acme']);

    Livewire::actingAs($user->fresh())
        ->test(LeadConvert::class, ['lead' => $lead])
        ->assertSee('New person')
        ->call('convert')
        ->assertHasNoErrors();

    expect($lead->refresh()->isConverted())->toBeTrue();
});
