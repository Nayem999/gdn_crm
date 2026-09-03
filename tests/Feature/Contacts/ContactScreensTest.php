<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\ContactExportSource;
use App\Domain\Contacts\Enums\Department;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Shared\Enums\ExportFormat;
use App\Domain\Shared\Enums\FilterOperator;
use App\Domain\Shared\Enums\ViewMode;
use App\Domain\Shared\Models\UserViewPreference;
use App\Livewire\Accounts\AccountShow;
use App\Livewire\Contacts\ContactForm;
use App\Livewire\Contacts\ContactShow;
use App\Livewire\Contacts\ContactsIndex;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

/**
 * @param  array<int, string>  $permissions
 */
function contactUser(array $permissions = ['contacts.view']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

function contactAdmin(): User
{
    return contactUser([
        'contacts.view', 'contacts.create', 'contacts.update', 'contacts.delete', 'contacts.export',
        'accounts.view',
    ]);
}

beforeEach(function () {
    Cache::flush();
});

afterEach(function () {
    Carbon::setTestNow();
});

// -- Access --------------------------------------------------------------------

test('a guest is sent to sign in', function () {
    $this->get(route('contacts.index'))->assertRedirect(route('login'));
});

test('the list needs the contacts.view permission', function () {
    $this->actingAs(User::factory()->create())->get(route('contacts.index'))->assertForbidden();

    $this->actingAs(contactUser())->get(route('contacts.index'))->assertOk()->assertSee('Contacts');
});

test('creating needs the create permission', function () {
    $this->actingAs(contactUser())->get(route('contacts.create'))->assertForbidden();

    $this->actingAs(contactUser(['contacts.view', 'contacts.create']))
        ->get(route('contacts.create'))
        ->assertOk()
        ->assertSee('New contact');
});

test('editing needs the update permission', function () {
    $user = contactUser();
    $contact = Contact::factory()->ownedBy($user)->create();

    $this->actingAs($user)->get(route('contacts.edit', $contact))->assertForbidden();

    $editor = contactUser(['contacts.view', 'contacts.update']);
    $theirs = Contact::factory()->ownedBy($editor)->create();

    $this->actingAs($editor)->get(route('contacts.edit', $theirs))->assertOk();
});

test('a record outside the access level cannot be reached by guessing its id', function () {
    $user = contactUser();
    $other = Contact::factory()->create();

    $this->actingAs($user)->get(route('contacts.show', $other))->assertForbidden();

    expect($user->can('view', $other))->toBeFalse();
});

test('the list only shows what the viewer may see', function () {
    $user = contactUser();

    Contact::factory()->ownedBy($user)->named('Mine', 'Contact')->create();
    Contact::factory()->named('Hidden', 'Person')->create();

    Livewire::actingAs($user)
        ->test(ContactsIndex::class)
        ->assertSee('Mine Contact')
        ->assertDontSee('Hidden Person');
});

// -- Create, update, delete ----------------------------------------------------

test('a contact can be created', function () {
    $user = contactAdmin();
    $account = Account::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(ContactForm::class)
        ->set('first_name', 'Dana')
        ->set('last_name', 'Scully')
        ->set('job_title', 'Chief Medical Officer')
        ->set('department', Department::Executive->value)
        ->set('email', 'dana@example.test')
        ->set('account_id', (string) $account->id)
        ->set('owner_id', (string) $user->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $contact = Contact::query()->firstOrFail();

    expect($contact->fullName())->toBe('Dana Scully')
        ->and($contact->department())->toBe(Department::Executive)
        ->and($contact->account_id)->toBe($account->id)
        // First at the account, so primary without being asked.
        ->and($contact->is_primary)->toBeTrue();
});

test('a contact can be created with no account', function () {
    $user = contactAdmin();

    Livewire::actingAs($user)
        ->test(ContactForm::class)
        ->set('first_name', 'Dana')
        ->set('last_name', 'Scully')
        ->call('save')
        ->assertHasNoErrors();

    $contact = Contact::query()->firstOrFail();

    expect($contact->account_id)->toBeNull()
        ->and($contact->is_primary)->toBeFalse();
});

test('creating validates the required and bounded fields', function () {
    Livewire::actingAs(contactAdmin())
        ->test(ContactForm::class)
        ->set('first_name', '')
        ->set('last_name', '')
        ->set('email', 'not-an-email')
        ->set('department', 'time-travel')
        ->call('save')
        ->assertHasErrors(['first_name', 'last_name', 'email', 'department']);

    expect(Contact::query()->count())->toBe(0);
});

test('a contact cannot be attached to an account the person cannot see', function () {
    $user = contactAdmin();
    $hidden = Account::factory()->create();

    Livewire::actingAs($user)
        ->test(ContactForm::class)
        ->set('first_name', 'Dana')
        ->set('last_name', 'Scully')
        ->set('account_id', (string) $hidden->id)
        ->call('save')
        ->assertHasErrors(['account_id']);

    expect(Contact::query()->count())->toBe(0);
});

test('an account handed in by the url is ignored when it is not visible', function () {
    $user = contactAdmin();
    $hidden = Account::factory()->create();
    $visible = Account::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->withQueryParams(['account' => $hidden->id])
        ->test(ContactForm::class)
        ->assertSet('account_id', null);

    Livewire::actingAs($user)
        ->withQueryParams(['account' => $visible->id])
        ->test(ContactForm::class)
        ->assertSet('account_id', (string) $visible->id);
});

test('a contact can be edited', function () {
    $user = contactAdmin();
    $contact = Contact::factory()->ownedBy($user)->named('Before', 'Name')->create();

    Livewire::actingAs($user)
        ->test(ContactForm::class, ['contact' => $contact])
        ->assertSet('first_name', 'Before')
        ->set('first_name', 'After')
        ->set('city', 'Leeds')
        ->call('save')
        ->assertHasNoErrors();

    expect($contact->fresh()->first_name)->toBe('After')
        ->and($contact->fresh()->city)->toBe('Leeds');
});

test('a contact can be removed from its own page', function () {
    $user = contactAdmin();
    $account = Account::factory()->ownedBy($user)->create();
    $primary = Contact::factory()->ownedBy($user)->forAccount($account)->primary()->create();
    $colleague = Contact::factory()->ownedBy($user)->forAccount($account)->create();

    Livewire::actingAs($user)
        ->test(ContactShow::class, ['contact' => $primary])
        ->call('delete')
        ->assertRedirect(route('contacts.index'));

    expect($primary->fresh()->trashed())->toBeTrue()
        // The flag passed on rather than leaving the account with none.
        ->and($colleague->fresh()->is_primary)->toBeTrue();
});

test('removing needs the delete permission', function () {
    $user = contactUser(['contacts.view', 'contacts.update']);
    $contact = Contact::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(ContactShow::class, ['contact' => $contact])
        ->call('delete')
        ->assertForbidden();

    expect($contact->fresh()->trashed())->toBeFalse();
});

test('a bulk removal only touches records the viewer may delete', function () {
    $user = contactAdmin();
    $mine = Contact::factory()->ownedBy($user)->create();
    $theirs = Contact::factory()->create();

    Livewire::actingAs($user)
        ->test(ContactsIndex::class)
        ->set('selected', [$mine->id, $theirs->id])
        ->call('deleteSelected');

    expect($mine->fresh()->trashed())->toBeTrue()
        ->and($theirs->fresh()->trashed())->toBeFalse();
});

// -- Primary contact from the UI -----------------------------------------------

test('a contact can be promoted to primary from its own page', function () {
    $user = contactAdmin();
    $account = Account::factory()->ownedBy($user)->create();
    $held = Contact::factory()->ownedBy($user)->forAccount($account)->primary()->create();
    $contact = Contact::factory()->ownedBy($user)->forAccount($account)->create();

    Livewire::actingAs($user)
        ->test(ContactShow::class, ['contact' => $contact])
        ->call('makePrimary')
        ->assertDispatched('contact-updated');

    expect($contact->fresh()->is_primary)->toBeTrue()
        ->and($held->fresh()->is_primary)->toBeFalse();
});

test('promoting needs the update permission', function () {
    $user = contactUser();
    $account = Account::factory()->create();
    $contact = Contact::factory()->ownedBy($user)->forAccount($account)->create();

    Livewire::actingAs($user)
        ->test(ContactShow::class, ['contact' => $contact])
        ->call('makePrimary')
        ->assertForbidden();

    expect($contact->fresh()->is_primary)->toBeFalse();
});

test('promoting a contact with no account says why', function () {
    $user = contactAdmin();
    $contact = Contact::factory()->ownedBy($user)->create(['account_id' => null]);

    Livewire::actingAs($user)
        ->test(ContactShow::class, ['contact' => $contact])
        ->call('makePrimary')
        ->assertDispatched('notify');

    expect($contact->fresh()->is_primary)->toBeFalse();
});

// -- The detail page -----------------------------------------------------------

test('the detail page shows the profile and who else is at the account', function () {
    $user = contactAdmin();
    $account = Account::factory()->ownedBy($user)->create(['name' => 'Acme Corporation']);

    $contact = Contact::factory()->ownedBy($user)->forAccount($account)->primary()
        ->named('Dana', 'Scully')->create(['job_title' => 'CFO', 'city' => 'Leeds']);
    Contact::factory()->ownedBy($user)->forAccount($account)->named('Fox', 'Mulder')->create();

    Livewire::actingAs($user)
        ->test(ContactShow::class, ['contact' => $contact])
        ->assertSee('Dana Scully')
        ->assertSee('CFO')
        ->assertSee('Leeds')
        ->assertSee('Acme Corporation')
        ->assertSee('Primary contact')
        ->assertSee('Fox Mulder');
});

test('a colleague the viewer cannot see is not listed', function () {
    $user = contactUser();
    $account = Account::factory()->create();
    $contact = Contact::factory()->ownedBy($user)->forAccount($account)->named('Dana', 'Scully')->create();
    Contact::factory()->forAccount($account)->named('Hidden', 'Colleague')->create();

    Livewire::actingAs($user)
        ->test(ContactShow::class, ['contact' => $contact])
        ->assertSee('only contact')
        ->assertDontSee('Hidden Colleague');
});

test('a contact with no account says so rather than listing nobody', function () {
    $user = contactAdmin();
    $contact = Contact::factory()->ownedBy($user)->create(['account_id' => null]);

    Livewire::actingAs($user)
        ->test(ContactShow::class, ['contact' => $contact])
        ->assertSee('not linked to an account yet');
});

// -- The account relation ------------------------------------------------------

test('an account page lists its contacts, primary first', function () {
    $user = contactAdmin();
    $user->givePermissionTo(PermissionResolver::models(['accounts.view'])[0]);
    $account = Account::factory()->ownedBy($user)->create();

    Contact::factory()->ownedBy($user)->forAccount($account)->named('Zoe', 'Zeta')->create();
    Contact::factory()->ownedBy($user)->forAccount($account)->primary()->named('Dana', 'Scully')->create();

    Livewire::actingAs($user)
        ->test(AccountShow::class, ['account' => $account])
        ->assertSee('Contacts')
        ->assertSeeInOrder(['Dana Scully', 'Zoe Zeta'])
        ->assertSee('Primary');
});

test('an account page does not list a contact the viewer cannot see', function () {
    $user = contactUser(['contacts.view', 'accounts.view']);
    $account = Account::factory()->ownedBy($user)->create();

    Contact::factory()->forAccount($account)->named('Hidden', 'Person')->create();

    Livewire::actingAs($user)
        ->test(AccountShow::class, ['account' => $account])
        ->assertSee('No contacts here yet')
        ->assertDontSee('Hidden Person');
});

test('the list can be narrowed to one account', function () {
    $user = contactAdmin();
    $account = Account::factory()->ownedBy($user)->create(['name' => 'Acme Corporation']);

    Contact::factory()->ownedBy($user)->forAccount($account)->named('Inside', 'Account')->create();
    Contact::factory()->ownedBy($user)->named('Outside', 'Account')->create();

    Livewire::actingAs($user)
        ->withQueryParams(['account' => $account->id])
        ->test(ContactsIndex::class)
        ->assertSee('Inside Account')
        ->assertDontSee('Outside Account')
        ->assertSee('Acme Corporation');
});

// -- Views ---------------------------------------------------------------------

test('every view mode renders with data', function (ViewMode $mode) {
    $user = contactUser();
    Contact::factory()->ownedBy($user)->named('Dana', 'Scully')->create();

    Livewire::actingAs($user)
        ->test(ContactsIndex::class)
        ->call('setViewMode', $mode->value)
        ->assertOk()
        ->assertSee('Dana Scully');
})->with(ViewMode::cases());

test('every view mode renders when there is nothing', function (ViewMode $mode) {
    Livewire::actingAs(contactUser())
        ->test(ContactsIndex::class)
        ->call('setViewMode', $mode->value)
        ->assertOk()
        ->assertSee('No contacts yet');
})->with(ViewMode::cases());

test('the kanban board is grouped by department', function () {
    $user = contactUser();
    Contact::factory()->ownedBy($user)->department(Department::Sales)->named('Dana', 'Scully')->create();

    $component = Livewire::actingAs($user)->test(ContactsIndex::class)->call('setViewMode', ViewMode::Kanban->value);

    expect($component->instance()->dataViewKanbanField())->toBe('department')
        ->and($component->instance()->dataViewKanbanColumns())->toHaveCount(count(Department::cases()));

    $component->assertSee('Dana Scully')->assertSee('Sales');
});

test('a kanban drag moves the contact to another department', function () {
    $user = contactAdmin();
    $contact = Contact::factory()->ownedBy($user)->department(Department::Sales)->create();

    Livewire::actingAs($user)
        ->test(ContactsIndex::class)
        ->call('moveCard', $contact->id, Department::Finance->value);

    expect($contact->fresh()->department())->toBe(Department::Finance);
});

test('a kanban drag to a department that does not exist is refused', function () {
    $user = contactAdmin();
    $contact = Contact::factory()->ownedBy($user)->department(Department::Sales)->create();

    Livewire::actingAs($user)
        ->test(ContactsIndex::class)
        ->call('moveCard', $contact->id, 'time-travel');

    expect($contact->fresh()->department())->toBe(Department::Sales);
});

test('the view mode persists per user', function () {
    $user = contactUser();

    Livewire::actingAs($user)->test(ContactsIndex::class)->call('setViewMode', ViewMode::List->value);

    expect(UserViewPreference::lookup($user, 'contacts')->view_mode)->toBe(ViewMode::List->value);

    Livewire::actingAs($user)->test(ContactsIndex::class)->assertSet('viewMode', ViewMode::List->value);
});

test('a hidden column persists and leaves the table', function () {
    $user = contactUser();
    Contact::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(ContactsIndex::class)
        ->assertSee("wire:click=\"sort('job_title')\"", false)
        ->call('toggleColumn', 'job_title')
        ->assertDontSee("wire:click=\"sort('job_title')\"", false);

    expect(UserViewPreference::lookup($user, 'contacts')->columns)->not->toContain('job_title');

    Livewire::actingAs($user)
        ->test(ContactsIndex::class)
        ->assertDontSee("wire:click=\"sort('job_title')\"", false);
});

// -- Search, filters, sorting --------------------------------------------------

test('searching narrows the list, including the full name', function () {
    $user = contactUser();
    Contact::factory()->ownedBy($user)->named('Dana', 'Scully')->create();
    Contact::factory()->ownedBy($user)->named('Fox', 'Mulder')->create();

    $component = Livewire::actingAs($user)->test(ContactsIndex::class);

    $component->set('search', 'Scully')->assertSee('Dana Scully')->assertDontSee('Fox Mulder');
    $component->set('search', 'Fox Mulder')->assertSee('Fox Mulder')->assertDontSee('Dana Scully');
});

test('a filter condition narrows the list', function () {
    $user = contactUser();
    Contact::factory()->ownedBy($user)->department(Department::Sales)->named('In', 'Sales')->create();
    Contact::factory()->ownedBy($user)->department(Department::Legal)->named('In', 'Legal')->create();

    Livewire::actingAs($user)
        ->test(ContactsIndex::class)
        ->call('addCondition')
        ->set('filters.conditions.0.field', 'department')
        ->set('filters.conditions.0.operator', FilterOperator::Equals->value)
        ->set('filters.conditions.0.value', Department::Sales->value)
        ->assertSee('In Sales')
        ->assertDontSee('In Legal');
});

test('the primary flag can be filtered as a boolean', function () {
    $user = contactUser();
    $account = Account::factory()->ownedBy($user)->create();

    Contact::factory()->ownedBy($user)->forAccount($account)->primary()->named('The', 'Primary')->create();
    Contact::factory()->ownedBy($user)->forAccount($account)->named('Not', 'Primary')->create();

    Livewire::actingAs($user)
        ->test(ContactsIndex::class)
        ->call('addCondition')
        ->set('filters.conditions.0.field', 'is_primary')
        ->set('filters.conditions.0.operator', FilterOperator::IsTrue->value)
        ->assertSee('The Primary')
        ->assertDontSee('Not Primary');
});

test('the quick filter chips narrow the list and toggle off again', function () {
    $user = contactUser();
    $account = Account::factory()->ownedBy($user)->create();

    Contact::factory()->ownedBy($user)->forAccount($account)->primary()->named('Linked', 'Primary')->create();
    Contact::factory()->ownedBy($user)->named('Free', 'Agent')->create(['account_id' => null]);

    $component = Livewire::actingAs($user)->test(ContactsIndex::class);

    $component->call('setQuickFilter', 'unlinked')
        ->assertSee('Free Agent')
        ->assertDontSee('Linked Primary');

    $component->call('setQuickFilter', 'unlinked')
        ->assertSet('quickFilter', '')
        ->assertSee('Linked Primary');

    $component->call('setQuickFilter', 'primary')
        ->assertSee('Linked Primary')
        ->assertDontSee('Free Agent');

    $component->call('setQuickFilter', 'nonsense')->assertSet('quickFilter', '');
});

test('sorting the name column orders by surname', function () {
    $user = contactUser();
    Contact::factory()->ownedBy($user)->named('Zoe', 'Adams')->create();
    Contact::factory()->ownedBy($user)->named('Adam', 'Zeta')->create();

    // Sorting on first name would put Adam first; the column sorts by surname.
    Livewire::actingAs($user)
        ->test(ContactsIndex::class)
        ->call('sort', 'name')
        ->assertSeeInOrder(['Zoe Adams', 'Adam Zeta']);
});

// -- The account picker's server-side search -----------------------------------

test('the account picker searches server-side and pages', function () {
    $user = contactAdmin();

    // One page plus one, so hasMore has something to be true about.
    Account::factory()->ownedBy($user)->count(ContactForm::ACCOUNTS_PER_PAGE + 1)->create();

    $component = Livewire::actingAs($user)->test(ContactForm::class);

    $first = $component->instance()->searchAccounts('', 1);

    expect($first['options'])->toHaveCount(ContactForm::ACCOUNTS_PER_PAGE)
        ->and($first['hasMore'])->toBeTrue();

    $second = $component->instance()->searchAccounts('', 2);

    expect($second['options'])->toHaveCount(1)
        ->and($second['hasMore'])->toBeFalse()
        // A different page really is different rows.
        ->and($second['options'][0]['value'])->not->toBe($first['options'][0]['value']);
});

test('the account picker filters by the typed term', function () {
    $user = contactAdmin();
    Account::factory()->ownedBy($user)->create(['name' => 'Acme Corporation']);
    Account::factory()->ownedBy($user)->create(['name' => 'Beta Industries']);

    $found = Livewire::actingAs($user)->test(ContactForm::class)->instance()->searchAccounts('Acme');

    expect($found['options'])->toHaveCount(1)
        ->and($found['options'][0]['label'])->toBe('Acme Corporation');
});

test('the account picker never returns an account the person cannot see', function () {
    $user = contactAdmin();
    Account::factory()->ownedBy($user)->create(['name' => 'Visible Ltd']);
    Account::factory()->create(['name' => 'Hidden Ltd']);

    $found = Livewire::actingAs($user)->test(ContactForm::class)->instance()->searchAccounts();

    expect(array_column($found['options'], 'label'))->toBe(['Visible Ltd']);
});

test('the picker survives the arguments the browser actually sends', function () {
    // Tom Select sends the query as null on a preload and on a cleared box, and
    // the page as a string. A strict string parameter turned that into a 500
    // that the dropdown swallowed into a silent "No matches found" — caught in
    // the browser, not by a test that only ever passed ''.
    $user = contactAdmin();
    Account::factory()->ownedBy($user)->create(['name' => 'Acme Corporation']);

    $component = Livewire::actingAs($user)->test(ContactForm::class);

    expect($component->instance()->searchAccounts(null)['options'])->toHaveCount(1)
        ->and($component->instance()->searchAccounts(null, '1')['options'])->toHaveCount(1)
        ->and($component->call('searchAccounts', null, 1)->effects['returns'][0]['options'])->toHaveCount(1);
});

test('a page below one is treated as the first page', function () {
    $user = contactAdmin();
    Account::factory()->ownedBy($user)->create();

    $found = Livewire::actingAs($user)->test(ContactForm::class)->instance()->searchAccounts('', 0);

    expect($found['options'])->toHaveCount(1);
});

test('every select on the contact form uses the shared component', function () {
    $rendered = Livewire::actingAs(contactAdmin())->test(ContactForm::class)->html();

    // Three dropdowns: department, account (server-side), owner.
    expect(substr_count($rendered, 'tomSelectField('))->toBe(3)
        ->and(substr_count($rendered, '<select'))->toBe(3)
        // The account one is wired to the paginated search.
        ->and($rendered)->toContain('searchAccounts');
});

// -- Export --------------------------------------------------------------------

test('exporting needs the export permission', function () {
    expect(Livewire::actingAs(contactUser())->test(ContactsIndex::class)->instance()->canExport())->toBeFalse()
        ->and(Livewire::actingAs(contactAdmin())->test(ContactsIndex::class)->instance()->canExport())->toBeTrue();
});

test('an export downloads and respects the active filter', function () {
    Excel::fake();
    Carbon::setTestNow(Carbon::parse('2026-01-01 09:00:00'));

    $user = contactAdmin();
    Contact::factory()->ownedBy($user)->named('Dana', 'Scully')->create();

    Livewire::actingAs($user)
        ->test(ContactsIndex::class)
        ->set('search', 'Scully')
        ->call('export', ExportFormat::Csv->value)
        ->assertOk();

    Excel::assertDownloaded('contacts-2026-01-01-090000.csv');
});

test('an export can never contain rows the person could not see', function () {
    $user = contactAdmin();
    Contact::factory()->ownedBy($user)->named('Mine', 'Contact')->create();
    Contact::factory()->named('Hidden', 'Contact')->create();

    $request = Livewire::actingAs($user)
        ->test(ContactsIndex::class)
        ->instance()
        ->exportRequestForTesting(ExportFormat::Csv);

    $rows = app(ContactExportSource::class)->exportQuery($request)->pluck('first_name')->all();

    expect($rows)->toBe(['Mine']);
});

test('the export writes readable values rather than stored ones', function () {
    $user = contactAdmin();
    $account = Account::factory()->ownedBy($user)->create(['name' => 'Acme Corporation']);

    Contact::factory()->ownedBy($user)->forAccount($account)->primary()
        ->department(Department::Finance)->named('Dana', 'Scully')->create();

    $request = Livewire::actingAs($user)
        ->test(ContactsIndex::class)
        ->instance()
        ->exportRequestForTesting(ExportFormat::Csv);

    $source = app(ContactExportSource::class);
    $row = $source->exportRow($source->exportQuery($request)->firstOrFail(), $request);

    expect($row)->toContain('Dana Scully')
        ->and($row)->toContain('Acme Corporation')
        ->and($row)->toContain(Department::Finance->label())
        ->and($row)->toContain('Yes')
        ->and($row)->toContain($user->name);
});
