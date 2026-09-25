<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Accounts\Models\Account;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Models\Deal;
use App\Livewire\Contacts\ContactForm;
use App\Livewire\Deals\DealForm;
use App\Models\User;
use Livewire\Livewire;

function campaignAttributionUser(): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models([
        'accounts.view', 'contacts.view', 'contacts.create', 'contacts.update',
        'deals.view', 'deals.create', 'deals.update', 'campaigns.view',
    ]) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

test('a contact can be attributed to a campaign', function () {
    $user = campaignAttributionUser();
    $campaign = Campaign::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(ContactForm::class)
        ->set('first_name', 'Farhana')
        ->set('last_name', 'Akter')
        ->set('email', 'farhana@example.com')
        ->set('owner_id', (string) $user->id)
        ->set('campaign_id', (string) $campaign->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Contact::query()->sole()->campaign_id)->toBe($campaign->id);
});

test('a deal can be attributed to a campaign', function () {
    $user = campaignAttributionUser();
    $campaign = Campaign::factory()->ownedBy($user)->create();
    $account = Account::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(DealForm::class)
        ->set('name', 'Renewal')
        ->set('account_id', (string) $account->id)
        ->set('campaign_id', (string) $campaign->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Deal::query()->sole()->campaign_id)->toBe($campaign->id);
});

test('neither form accepts a campaign outside the access level', function (string $form) {
    $user = campaignAttributionUser();
    $hidden = Campaign::factory()->create();
    $account = Account::factory()->ownedBy($user)->create();

    $component = Livewire::actingAs($user)->test($form);

    $form === ContactForm::class
        ? $component->set('first_name', 'A')->set('last_name', 'B')->set('email', 'a@example.com')->set('owner_id', (string) $user->id)
        : $component->set('name', 'Renewal')->set('account_id', (string) $account->id);

    $component->set('campaign_id', (string) $hidden->id)->call('save')->assertHasErrors('campaign_id');
})->with([
    'contact' => [ContactForm::class],
    'deal' => [DealForm::class],
]);

test('editing a deal keeps an attribution the editor cannot see', function () {
    $user = campaignAttributionUser();
    $theirs = Campaign::factory()->create();
    $deal = Deal::factory()->ownedBy($user)->create([
        'account_id' => Account::factory()->ownedBy($user)->create()->id,
        'campaign_id' => $theirs->id,
    ]);

    Livewire::actingAs($user)
        ->test(DealForm::class, ['deal' => $deal])
        ->set('description', 'Budget confirmed.')
        ->call('save')
        ->assertHasNoErrors();

    expect($deal->fresh()->campaign_id)->toBe($theirs->id);
});
