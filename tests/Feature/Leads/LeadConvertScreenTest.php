<?php

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\PermissionResolver;
use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Livewire\Leads\LeadConvert;
use App\Livewire\Leads\LeadShow;
use App\Models\User;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function convertUser(array $permissions = ['leads.view', 'leads.convert']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

function screenLead(User $owner): Lead
{
    return Lead::factory()->ownedBy($owner)->status(LeadStatus::Qualified)->named('Dara', 'Okafor')
        ->create([
            'company_name' => 'Acme Industries',
            'email' => 'dara@acme.test',
            'estimated_value' => '45000.00',
        ]);
}

// -- Access --------------------------------------------------------------------

test('a guest is sent to sign in', function () {
    $lead = Lead::factory()->create();

    $this->get(route('leads.convert', $lead))->assertRedirect(route('login'));
});

test('converting needs its own permission, not just the right to edit', function () {
    $user = convertUser(['leads.view', 'leads.update', 'leads.delete']);
    $lead = screenLead($user);

    $this->actingAs($user)->get(route('leads.convert', $lead))->assertForbidden();
});

test('somebody with the permission gets the screen', function () {
    $user = convertUser();
    $lead = screenLead($user);

    $this->actingAs($user)->get(route('leads.convert', $lead))->assertOk()->assertSee('Convert Dara Okafor');
});

test('leads.convert is in the catalogue, so the roles matrix can grant it', function () {
    expect(PermissionCatalogue::has('leads.convert'))->toBeTrue()
        ->and(PermissionCatalogue::has('deals.view'))->toBeTrue();
});

test('a lead the viewer cannot see is refused', function () {
    $user = convertUser();
    $theirs = Lead::factory()->create();

    // The policy answers first, and it checks the access level as well as the
    // permission — the same 403 every other lead screen gives.
    $this->actingAs($user)->get(route('leads.convert', $theirs))->assertForbidden();
});

// -- What the screen offers -------------------------------------------------------

test('the form is filled in from the lead', function () {
    $user = convertUser();
    $lead = screenLead($user);

    Livewire::actingAs($user)
        ->test(LeadConvert::class, ['lead' => $lead])
        ->assertSet('accountName', 'Acme Industries')
        ->assertSet('dealName', 'Acme Industries opportunity')
        ->assertSet('dealValue', '45000.00')
        ->assertSet('ownerId', (string) $user->id)
        ->assertSet('createDeal', true);
});

test('accounts that already look like the lead are offered instead of a second copy', function () {
    $user = convertUser(['leads.view', 'leads.convert', 'accounts.view']);
    $existing = Account::factory()->ownedBy($user)->create(['name' => 'Acme Industries Ltd']);
    $lead = screenLead($user);

    $component = Livewire::actingAs($user)->test(LeadConvert::class, ['lead' => $lead]);

    expect($component->instance()->accountOptions())->toHaveKey((string) $existing->id)
        ->and($component->instance()->accountOptions()[''])->toBe('Create a new account');

    $component->assertSee('Acme Industries Ltd');
});

test('people already on file are offered too', function () {
    $user = convertUser(['leads.view', 'leads.convert', 'contacts.view']);
    $existing = Contact::factory()->ownedBy($user)->create(['email' => 'dara@acme.test']);
    $lead = screenLead($user);

    $component = Livewire::actingAs($user)->test(LeadConvert::class, ['lead' => $lead]);

    expect($component->instance()->contactOptions())->toHaveKey((string) $existing->id);
});

test('a record outside the viewer access level is never offered', function () {
    $user = convertUser();
    Account::factory()->create(['name' => 'Acme Industries Ltd']);
    $lead = screenLead($user);

    $component = Livewire::actingAs($user)->test(LeadConvert::class, ['lead' => $lead]);

    expect($component->instance()->accountOptions())->toBe(['' => 'Create a new account']);
});

// -- Converting ---------------------------------------------------------------------

test('converting from the screen creates all three and goes to the account', function () {
    $user = convertUser();
    $lead = screenLead($user);

    Livewire::actingAs($user)
        ->test(LeadConvert::class, ['lead' => $lead])
        ->call('convert')
        ->assertHasNoErrors()
        ->assertRedirect(route('accounts.show', Account::query()->firstOrFail()));

    expect(Account::query()->count())->toBe(1)
        ->and(Contact::query()->count())->toBe(1)
        ->and(Deal::query()->count())->toBe(1)
        ->and($lead->fresh()->status())->toBe(LeadStatus::Converted);
});

test('the deal can be left out', function () {
    $user = convertUser();
    $lead = screenLead($user);

    Livewire::actingAs($user)
        ->test(LeadConvert::class, ['lead' => $lead])
        ->set('createDeal', false)
        ->call('convert')
        ->assertHasNoErrors();

    expect(Deal::query()->count())->toBe(0)
        ->and(Account::query()->count())->toBe(1);
});

test('a new account needs a name', function () {
    $user = convertUser();
    $lead = screenLead($user);

    Livewire::actingAs($user)
        ->test(LeadConvert::class, ['lead' => $lead])
        ->set('accountName', '')
        ->call('convert')
        ->assertHasErrors('accountName');

    expect(Account::query()->count())->toBe(0);
});

test('a deal value that would not fit the column is refused', function () {
    $user = convertUser();
    $lead = screenLead($user);

    Livewire::actingAs($user)
        ->test(LeadConvert::class, ['lead' => $lead])
        ->set('dealValue', '99999999999999999')
        ->call('convert')
        ->assertHasErrors('dealValue');
});

test('an account somebody cannot reach cannot be chosen, however the id arrives', function () {
    $user = convertUser();
    $theirs = Account::factory()->create();
    $lead = screenLead($user);

    // "exists" proves a record is real, never that this person may reach it.
    Livewire::actingAs($user)
        ->test(LeadConvert::class, ['lead' => $lead])
        ->set('accountId', (string) $theirs->id)
        ->call('convert')
        ->assertHasErrors('accountId');

    expect($lead->fresh()->isConverted())->toBeFalse();
});

test('a contact somebody cannot reach cannot be chosen either', function () {
    $user = convertUser();
    $theirs = Contact::factory()->create();
    $lead = screenLead($user);

    Livewire::actingAs($user)
        ->test(LeadConvert::class, ['lead' => $lead])
        ->set('contactId', (string) $theirs->id)
        ->call('convert')
        ->assertHasErrors('contactId');
});

test('an unqualified lead is turned away with a reason rather than a crash', function () {
    $user = convertUser();
    $lead = Lead::factory()->ownedBy($user)->status(LeadStatus::Unqualified)->create();

    Livewire::actingAs($user)
        ->test(LeadConvert::class, ['lead' => $lead])
        ->call('convert')
        ->assertDispatched('notify', type: 'error')
        ->assertNoRedirect();

    expect(Account::query()->count())->toBe(0);
});

test('converting an already converted lead says so and creates nothing more', function () {
    $user = convertUser();
    $lead = screenLead($user);

    Livewire::actingAs($user)->test(LeadConvert::class, ['lead' => $lead])->call('convert');

    Livewire::actingAs($user)
        ->test(LeadConvert::class, ['lead' => $lead->fresh()])
        ->call('convert')
        ->assertHasNoErrors();

    expect(Account::query()->count())->toBe(1)
        ->and(Contact::query()->count())->toBe(1)
        ->and(Deal::query()->count())->toBe(1);
});

// -- The lead's own page ---------------------------------------------------------------

test('a lead still in play offers conversion', function () {
    $user = convertUser(['leads.view', 'leads.update', 'leads.convert']);
    $lead = screenLead($user);

    Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => $lead])
        ->assertSee('Convert')
        ->assertSee(route('leads.convert', $lead), false);
});

test('somebody who cannot convert is not offered the button', function () {
    $user = convertUser(['leads.view', 'leads.update']);
    $lead = screenLead($user);

    Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => $lead])
        ->assertDontSee(route('leads.convert', $lead), false);
});

test('a converted lead shows what it became instead of the moves it could make', function () {
    $user = convertUser(['leads.view', 'leads.update', 'leads.convert', 'accounts.view', 'contacts.view']);
    $lead = screenLead($user);

    Livewire::actingAs($user)->test(LeadConvert::class, ['lead' => $lead])->call('convert');

    Livewire::actingAs($user)
        ->test(LeadShow::class, ['lead' => $lead->fresh()])
        ->assertSee('Converted')
        ->assertSee('Acme Industries')
        ->assertSee('Dara Okafor')
        ->assertSee('Acme Industries opportunity')
        // The transition buttons and the convert button are both gone.
        ->assertDontSee('Move this lead on')
        ->assertDontSee(route('leads.convert', $lead), false);
});

test('the convert screen uses the searchable select, not a plain dropdown', function () {
    $user = convertUser();
    $lead = screenLead($user);

    $html = Livewire::actingAs($user)->test(LeadConvert::class, ['lead' => $lead])->html();

    expect(substr_count($html, '<select'))->toBe(substr_count($html, 'tomSelectField('))
        ->and(substr_count($html, '<select'))->toBeGreaterThan(0);
});
