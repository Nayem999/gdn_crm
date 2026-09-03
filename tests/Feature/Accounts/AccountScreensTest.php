<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Accounts\AccountExportSource;
use App\Domain\Accounts\Enums\AccountSize;
use App\Domain\Accounts\Enums\Industry;
use App\Domain\Accounts\Models\Account;
use App\Domain\Shared\Enums\ExportFormat;
use App\Domain\Shared\Enums\FilterOperator;
use App\Domain\Shared\Enums\ViewMode;
use App\Domain\Shared\Models\UserViewPreference;
use App\Livewire\Accounts\AccountForm;
use App\Livewire\Accounts\AccountShow;
use App\Livewire\Accounts\AccountsIndex;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

/**
 * @param  array<int, string>  $permissions
 */
function accountUser(array $permissions = ['accounts.view']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

function accountAdmin(): User
{
    return accountUser([
        'accounts.view', 'accounts.create', 'accounts.update', 'accounts.delete', 'accounts.export',
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
    $this->get(route('accounts.index'))->assertRedirect(route('login'));
});

test('the list needs the accounts.view permission', function () {
    $this->actingAs(User::factory()->create())->get(route('accounts.index'))->assertForbidden();

    $this->actingAs(accountUser())->get(route('accounts.index'))->assertOk()->assertSee('Accounts');
});

test('creating needs the create permission', function () {
    $this->actingAs(accountUser())->get(route('accounts.create'))->assertForbidden();

    $this->actingAs(accountUser(['accounts.view', 'accounts.create']))
        ->get(route('accounts.create'))
        ->assertOk()
        ->assertSee('New account');
});

test('editing needs the update permission', function () {
    $user = accountUser();
    $account = Account::factory()->ownedBy($user)->create();

    $this->actingAs($user)->get(route('accounts.edit', $account))->assertForbidden();

    $editor = accountUser(['accounts.view', 'accounts.update']);
    $theirs = Account::factory()->ownedBy($editor)->create();

    $this->actingAs($editor)->get(route('accounts.edit', $theirs))->assertOk();
});

test('a record outside the access level cannot be reached by guessing its id', function () {
    $user = accountUser();
    $other = Account::factory()->create();

    // Visible in nobody else's list, and not reachable directly either.
    $this->actingAs($user)->get(route('accounts.show', $other))->assertForbidden();

    expect($user->can('view', $other))->toBeFalse();
});

test('the list only shows what the viewer may see', function () {
    $user = accountUser();

    Account::factory()->ownedBy($user)->create(['name' => 'Mine Ltd']);
    Account::factory()->create(['name' => 'Theirs Ltd']);

    Livewire::actingAs($user)
        ->test(AccountsIndex::class)
        ->assertSee('Mine Ltd')
        ->assertDontSee('Theirs Ltd');
});

// -- Create, update, delete ----------------------------------------------------

test('an account can be created', function () {
    $user = accountAdmin();

    Livewire::actingAs($user)
        ->test(AccountForm::class)
        ->set('name', 'Acme Corporation')
        ->set('industry', Industry::Technology->value)
        ->set('size', AccountSize::Large->value)
        ->set('annual_revenue', '1250000.50')
        ->set('website', 'acme.test')
        ->set('email', 'hello@acme.test')
        ->set('owner_id', (string) $user->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $account = Account::query()->firstOrFail();

    expect($account->name)->toBe('Acme Corporation')
        ->and($account->industry())->toBe(Industry::Technology)
        ->and($account->annual_revenue)->toBe('1250000.50')
        ->and($account->owner_id)->toBe($user->id);
});

test('creating validates the required and bounded fields', function () {
    Livewire::actingAs(accountAdmin())
        ->test(AccountForm::class)
        ->set('name', '')
        ->set('email', 'not-an-email')
        ->set('annual_revenue', '-5')
        ->set('industry', 'time-travel')
        ->set('size', 'gigantic')
        ->call('save')
        ->assertHasErrors(['name', 'email', 'annual_revenue', 'industry', 'size']);

    expect(Account::query()->count())->toBe(0);
});

test('revenue beyond what the column holds is refused rather than truncated', function () {
    Livewire::actingAs(accountAdmin())
        ->test(AccountForm::class)
        ->set('name', 'Huge')
        ->set('annual_revenue', '99999999999999.99')
        ->call('save')
        ->assertHasErrors(['annual_revenue']);
});

test('an account can be edited', function () {
    $user = accountAdmin();
    $account = Account::factory()->ownedBy($user)->create(['name' => 'Before']);

    Livewire::actingAs($user)
        ->test(AccountForm::class, ['account' => $account])
        ->assertSet('name', 'Before')
        ->set('name', 'After')
        ->set('city', 'Leeds')
        ->call('save')
        ->assertHasNoErrors();

    expect($account->fresh()->name)->toBe('After')
        ->and($account->fresh()->city)->toBe('Leeds');
});

test('the form refuses a parent that would create a loop', function () {
    $user = accountAdmin();
    $root = Account::factory()->ownedBy($user)->create();
    $child = Account::factory()->ownedBy($user)->childOf($root)->create();

    Livewire::actingAs($user)
        ->test(AccountForm::class, ['account' => $root])
        ->set('parent_id', (string) $child->id)
        ->call('save')
        ->assertHasErrors(['parent_id']);

    expect($root->fresh()->parent_id)->toBeNull();
});

test('the parent dropdown never offers the account itself or its descendants', function () {
    $user = accountAdmin();
    $root = Account::factory()->ownedBy($user)->create(['name' => 'Root']);
    $child = Account::factory()->ownedBy($user)->childOf($root)->create(['name' => 'Child']);
    $other = Account::factory()->ownedBy($user)->create(['name' => 'Other']);

    $options = Livewire::actingAs($user)
        ->test(AccountForm::class, ['account' => $root])
        ->instance()
        ->parentOptions();

    expect($options)->toHaveKey((string) $other->id)
        ->and($options)->not->toHaveKey((string) $root->id)
        ->and($options)->not->toHaveKey((string) $child->id);
});

test('the parent dropdown is scoped to what the viewer can see', function () {
    $user = accountAdmin();
    Account::factory()->ownedBy($user)->create(['name' => 'Visible Ltd']);
    Account::factory()->create(['name' => 'Hidden Ltd']);

    $options = Livewire::actingAs($user)->test(AccountForm::class)->instance()->parentOptions();

    expect(array_values($options))->toBe(['Visible Ltd']);
});

test('an account can be removed from its own page', function () {
    $user = accountAdmin();
    $account = Account::factory()->ownedBy($user)->create();
    $child = Account::factory()->ownedBy($user)->childOf($account)->create();

    Livewire::actingAs($user)
        ->test(AccountShow::class, ['account' => $account])
        ->call('delete')
        ->assertRedirect(route('accounts.index'));

    expect($account->fresh()->trashed())->toBeTrue()
        ->and($child->fresh()->parent_id)->toBeNull();
});

test('removing needs the delete permission', function () {
    $user = accountUser(['accounts.view', 'accounts.update']);
    $account = Account::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(AccountShow::class, ['account' => $account])
        ->call('delete')
        ->assertForbidden();

    expect($account->fresh()->trashed())->toBeFalse();
});

test('a bulk removal only touches records the viewer may delete', function () {
    $user = accountAdmin();
    $mine = Account::factory()->ownedBy($user)->create();
    $theirs = Account::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountsIndex::class)
        ->set('selected', [$mine->id, $theirs->id])
        ->call('deleteSelected');

    expect($mine->fresh()->trashed())->toBeTrue()
        ->and($theirs->fresh()->trashed())->toBeFalse();
});

// -- The detail page -----------------------------------------------------------

test('the detail page shows the profile and the hierarchy', function () {
    $user = accountAdmin();
    $root = Account::factory()->ownedBy($user)->create(['name' => 'Holding Group']);
    $middle = Account::factory()->ownedBy($user)->childOf($root)->create(['name' => 'Regional Arm']);
    $account = Account::factory()->ownedBy($user)->childOf($middle)->create([
        'name' => 'Local Branch',
        'city' => 'Leeds',
    ]);
    Account::factory()->ownedBy($user)->childOf($account)->create(['name' => 'Tiny Sub']);

    Livewire::actingAs($user)
        ->test(AccountShow::class, ['account' => $account])
        ->assertSee('Local Branch')
        ->assertSee('Leeds')
        // Breadcrumb, root first.
        ->assertSeeInOrder(['Holding Group', 'Regional Arm', 'Local Branch'])
        ->assertSee('Tiny Sub');
});

test('a subsidiary the viewer cannot see is not listed on the parent page', function () {
    $user = accountUser();
    $root = Account::factory()->ownedBy($user)->create(['name' => 'Root Ltd']);
    Account::factory()->childOf($root)->create(['name' => 'Hidden Sub']);

    Livewire::actingAs($user)
        ->test(AccountShow::class, ['account' => $root])
        ->assertSee('No subsidiaries')
        ->assertDontSee('Hidden Sub');
});

// -- Views ---------------------------------------------------------------------

test('every view mode renders with data', function (ViewMode $mode) {
    $user = accountUser();
    Account::factory()->ownedBy($user)->create(['name' => 'Acme Corporation']);

    Livewire::actingAs($user)
        ->test(AccountsIndex::class)
        ->call('setViewMode', $mode->value)
        ->assertOk()
        ->assertSee('Acme Corporation');
})->with(ViewMode::cases());

test('every view mode renders when there is nothing', function (ViewMode $mode) {
    Livewire::actingAs(accountUser())
        ->test(AccountsIndex::class)
        ->call('setViewMode', $mode->value)
        ->assertOk()
        ->assertSee('No accounts yet');
})->with(ViewMode::cases());

test('the kanban board is grouped by size band, not industry', function () {
    // Nineteen industry columns would push every card off-screen; five size
    // bands read as a segmentation board.
    $user = accountUser();
    Account::factory()->ownedBy($user)->size(AccountSize::Large)->create(['name' => 'Big Co']);

    $component = Livewire::actingAs($user)->test(AccountsIndex::class)->call('setViewMode', ViewMode::Kanban->value);

    expect($component->instance()->dataViewKanbanField())->toBe('size')
        ->and($component->instance()->dataViewKanbanColumns())->toHaveCount(count(AccountSize::cases()));

    $component->assertSee('Big Co')->assertSee('Large');
});

test('a kanban drag moves the account to another size band', function () {
    $user = accountAdmin();
    $account = Account::factory()->ownedBy($user)->size(AccountSize::Small)->create();

    Livewire::actingAs($user)
        ->test(AccountsIndex::class)
        ->call('moveCard', $account->id, AccountSize::Enterprise->value);

    expect($account->fresh()->size())->toBe(AccountSize::Enterprise);
});

test('a kanban drag to a band that does not exist is refused', function () {
    $user = accountAdmin();
    $account = Account::factory()->ownedBy($user)->size(AccountSize::Small)->create();

    Livewire::actingAs($user)
        ->test(AccountsIndex::class)
        ->call('moveCard', $account->id, 'gigantic');

    expect($account->fresh()->size())->toBe(AccountSize::Small);
});

test('the view mode persists per user', function () {
    $user = accountUser();

    Livewire::actingAs($user)->test(AccountsIndex::class)->call('setViewMode', ViewMode::Grid->value);

    expect(UserViewPreference::lookup($user, 'accounts')->view_mode)->toBe(ViewMode::Grid->value);

    Livewire::actingAs($user)->test(AccountsIndex::class)->assertSet('viewMode', ViewMode::Grid->value);
});

test('a hidden column persists and leaves the table', function () {
    $user = accountUser();
    Account::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(AccountsIndex::class)
        ->assertSee("wire:click=\"sort('city')\"", false)
        ->call('toggleColumn', 'city')
        ->assertDontSee("wire:click=\"sort('city')\"", false);

    expect(UserViewPreference::lookup($user, 'accounts')->columns)->not->toContain('city');

    Livewire::actingAs($user)
        ->test(AccountsIndex::class)
        ->assertDontSee("wire:click=\"sort('city')\"", false);
});

// -- Search, filters, sorting --------------------------------------------------

test('searching narrows the list', function () {
    $user = accountUser();
    Account::factory()->ownedBy($user)->create(['name' => 'Acme Corporation']);
    Account::factory()->ownedBy($user)->create(['name' => 'Beta Industries']);

    Livewire::actingAs($user)
        ->test(AccountsIndex::class)
        ->set('search', 'Acme')
        ->assertSee('Acme Corporation')
        ->assertDontSee('Beta Industries');
});

test('a filter condition narrows the list', function () {
    $user = accountUser();
    Account::factory()->ownedBy($user)->industry(Industry::Technology)->create(['name' => 'Tech Co']);
    Account::factory()->ownedBy($user)->industry(Industry::Retail)->create(['name' => 'Shop Co']);

    Livewire::actingAs($user)
        ->test(AccountsIndex::class)
        ->call('addCondition')
        ->set('filters.conditions.0.field', 'industry')
        ->set('filters.conditions.0.operator', 'equals')
        ->set('filters.conditions.0.value', Industry::Technology->value)
        ->assertSee('Tech Co')
        ->assertDontSee('Shop Co');
});

test('a numeric filter works on revenue', function () {
    $user = accountUser();
    Account::factory()->ownedBy($user)->create(['name' => 'Big Co', 'annual_revenue' => '5000000.00']);
    Account::factory()->ownedBy($user)->create(['name' => 'Small Co', 'annual_revenue' => '10000.00']);

    Livewire::actingAs($user)
        ->test(AccountsIndex::class)
        ->call('addCondition')
        ->set('filters.conditions.0.field', 'annual_revenue')
        ->set('filters.conditions.0.operator', FilterOperator::GreaterThan->value)
        ->set('filters.conditions.0.value', '1000000')
        ->assertSee('Big Co')
        ->assertDontSee('Small Co');
});

test('the quick filter chips narrow the list and toggle off again', function () {
    $user = accountUser();
    $mine = Account::factory()->ownedBy($user)->create(['name' => 'Mine Ltd']);
    Account::factory()->ownedBy($user)->childOf($mine)->create(['name' => 'Sub Ltd']);

    $component = Livewire::actingAs($user)->test(AccountsIndex::class);

    // Asserted on the query rather than the markup: the parent column shows
    // "Mine Ltd" inside its own subsidiary's row.
    $component->call('setQuickFilter', 'subsidiaries');

    expect($component->instance()->dataViewBaseQuery()->pluck('name')->all())->toBe(['Sub Ltd']);

    // Clicking the same chip again clears it.
    $component->call('setQuickFilter', 'subsidiaries')->assertSet('quickFilter', '');

    expect($component->instance()->dataViewBaseQuery()->pluck('name')->sort()->values()->all())
        ->toBe(['Mine Ltd', 'Sub Ltd']);

    $component->call('setQuickFilter', 'nonsense')->assertSet('quickFilter', '');
});

test('sorting reorders the list', function () {
    $user = accountUser();
    Account::factory()->ownedBy($user)->create(['name' => 'Zeta Ltd']);
    Account::factory()->ownedBy($user)->create(['name' => 'Alpha Ltd']);

    Livewire::actingAs($user)
        ->test(AccountsIndex::class)
        ->call('sort', 'name')
        ->assertSeeInOrder(['Alpha Ltd', 'Zeta Ltd'])
        ->call('sort', 'name')
        ->assertSeeInOrder(['Zeta Ltd', 'Alpha Ltd']);
});

// -- Export --------------------------------------------------------------------

test('exporting needs the export permission', function () {
    $viewer = accountUser();

    expect(Livewire::actingAs($viewer)->test(AccountsIndex::class)->instance()->canExport())->toBeFalse();

    expect(Livewire::actingAs(accountAdmin())->test(AccountsIndex::class)->instance()->canExport())->toBeTrue();
});

test('an export downloads and respects the active filter', function () {
    Excel::fake();
    // Frozen: the filename is stamped when the export is built, and a test that
    // recomputes now() races the second boundary under a full-suite run.
    Carbon::setTestNow(Carbon::parse('2026-01-01 09:00:00'));

    $user = accountAdmin();
    Account::factory()->ownedBy($user)->create(['name' => 'Acme Corporation']);

    Livewire::actingAs($user)
        ->test(AccountsIndex::class)
        ->set('search', 'Acme')
        ->call('export', ExportFormat::Csv->value)
        ->assertOk();

    Excel::assertDownloaded('accounts-'.now()->format('Y-m-d-His').'.csv');
});

test('an export can never contain rows the person could not see', function () {
    $user = accountAdmin();
    Account::factory()->ownedBy($user)->create(['name' => 'Mine Ltd']);
    Account::factory()->create(['name' => 'Hidden Ltd']);

    $request = Livewire::actingAs($user)
        ->test(AccountsIndex::class)
        ->instance()
        ->exportRequestForTesting(ExportFormat::Csv);

    $rows = app(AccountExportSource::class)->exportQuery($request)->pluck('name')->all();

    expect($rows)->toBe(['Mine Ltd']);
});

test('the export writes enum labels rather than stored values', function () {
    $user = accountAdmin();
    $account = Account::factory()->ownedBy($user)
        ->industry(Industry::Technology)
        ->size(AccountSize::Large)
        ->create();

    $request = Livewire::actingAs($user)
        ->test(AccountsIndex::class)
        ->instance()
        ->exportRequestForTesting(ExportFormat::Csv);

    $source = app(AccountExportSource::class);
    $row = $source->exportRow($source->exportQuery($request)->firstOrFail(), $request);

    expect($row)->toContain(Industry::Technology->label())
        ->and($row)->toContain(AccountSize::Large->label())
        ->and($row)->toContain($user->name);
});

test('every select on the account form uses the shared component', function () {
    $rendered = Livewire::actingAs(accountAdmin())->test(AccountForm::class)->html();

    // Four dropdowns: industry, size, owner, parent — all Tom Select backed.
    expect(substr_count($rendered, 'tomSelectField('))->toBe(4)
        // And no bare <select> escaped the component.
        ->and(substr_count($rendered, '<select'))->toBe(4);
});
