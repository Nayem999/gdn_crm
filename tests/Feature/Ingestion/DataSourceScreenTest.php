<?php

use App\Domain\Ingestion\Enums\DataSourceType;
use App\Domain\Ingestion\IngestionTargets;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Domain\Leads\Models\Lead;
use App\Domain\Settings\SettingsNavigation;
use App\Livewire\Settings\DataSources;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
});

// -- Getting to the screen -----------------------------------------------------

test('the screen renders for somebody who may view data sources', function () {
    $user = ingestionAdmin();
    DataSource::factory()->create(['name' => 'Delivery tool']);

    Livewire::actingAs($user)
        ->test(DataSources::class)
        ->assertOk()
        ->assertSee('Delivery tool');
});

test('the screen is refused to somebody without the view permission', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(DataSources::class)
        ->assertForbidden();
});

test('the route resolves and refuses the same way', function () {
    $this->actingAs(ingestionAdmin())->get(route('settings.data-sources'))->assertOk();
    $this->actingAs(User::factory()->create())->get(route('settings.data-sources'))->assertForbidden();
});

test('the settings navigation offers it only to somebody who may see it', function () {
    $labels = fn (User $user) => collect(SettingsNavigation::for($user))
        ->flatMap(fn (array $section) => array_column($section['items'], 'label'))
        ->all();

    expect($labels(ingestionAdmin()))->toContain('Data sources')
        // Hidden rather than shown and then refused.
        ->and($labels(User::factory()->create()))->not->toContain('Data sources');
});

// -- Creating ------------------------------------------------------------------

test('the form creates a source', function () {
    $user = ingestionAdmin();

    Livewire::actingAs($user)
        ->test(DataSources::class)
        ->call('add')
        ->set('name', 'Delivery tool')
        ->set('description', 'Tasks become leads')
        ->set('type', DataSourceType::Push->value)
        ->set('target_module', 'leads')
        ->call('save')
        ->assertHasNoErrors();

    $source = DataSource::query()->firstOrFail();

    expect($source->name)->toBe('Delivery tool')
        ->and($source->target_module)->toBe('leads')
        ->and($source->type())->toBe(DataSourceType::Push)
        ->and($source->is_active)->toBeTrue()
        ->and($source->created_by_id)->toBe($user->id)
        ->and($source->uuid)->not->toBeEmpty();
});

test('the form insists on a name and a target module', function () {
    Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->call('add')
        ->set('name', '')
        ->set('target_module', null)
        ->call('save')
        ->assertHasErrors(['name' => 'required', 'target_module' => 'required']);
});

test('a target module outside the registry is refused by validation', function () {
    Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->call('add')
        ->set('name', 'Something')
        ->set('target_module', 'users')
        ->call('save')
        ->assertHasErrors(['target_module']);

    expect(DataSource::query()->count())->toBe(0);
});

test('the form offers exactly the modules the gateway writes into', function () {
    $screen = Livewire::actingAs(ingestionAdmin())->test(DataSources::class)->instance();

    // The same registry the action checks against, so the list offered and the
    // list accepted cannot drift apart.
    expect(array_keys($screen->targetOptions()))->toBe(IngestionTargets::keys())
        ->and(array_keys($screen->typeOptions()))->toBe(['push', 'pull']);
});

// -- Editing -------------------------------------------------------------------

test('editing loads what is stored', function () {
    $source = DataSource::factory()->pull()->sandbox()->into('accounts')->create([
        'name' => 'Billing export',
        'description' => 'Nightly pull',
    ]);

    Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->call('edit', $source->id)
        ->assertSet('editingId', $source->id)
        ->assertSet('name', 'Billing export')
        ->assertSet('description', 'Nightly pull')
        ->assertSet('type', DataSourceType::Pull->value)
        ->assertSet('target_module', 'accounts')
        ->assertSet('is_sandbox', true);
});

test('the form reports a refused retarget against the field rather than throwing', function () {
    $source = DataSource::factory()->into('leads')->create();
    IntegrationEvent::factory()->forSource($source)->wrote(Lead::factory()->create())->create();

    Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->call('edit', $source->id)
        ->set('target_module', 'contacts')
        ->call('save')
        ->assertHasErrors(['target_module']);

    expect($source->fresh()->target_module)->toBe('leads');
});

test('cancelling leaves the source alone', function () {
    $source = DataSource::factory()->create(['name' => 'Delivery tool']);

    Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->call('edit', $source->id)
        ->set('name', 'Renamed')
        ->call('cancel')
        ->assertSet('editing', false);

    expect($source->fresh()->name)->toBe('Delivery tool');
});

// -- The switches --------------------------------------------------------------

test('a source can be switched off and on from the list', function () {
    $source = DataSource::factory()->create();

    $screen = Livewire::actingAs(ingestionAdmin())->test(DataSources::class);

    $screen->call('toggleActive', $source->id);
    expect($source->fresh()->is_active)->toBeFalse()
        ->and(DataSource::forIngest($source->uuid))->toBeNull();

    $screen->call('toggleActive', $source->id);
    expect($source->fresh()->is_active)->toBeTrue()
        ->and(DataSource::forIngest($source->uuid))->not->toBeNull();
});

test('sandbox mode can be turned on and off from the list', function () {
    $source = DataSource::factory()->create();

    $screen = Livewire::actingAs(ingestionAdmin())->test(DataSources::class);

    $screen->call('toggleSandbox', $source->id);
    expect($source->fresh()->is_sandbox)->toBeTrue()
        // Still accepting: sandbox captures, it does not close the door.
        ->and($source->fresh()->acceptsDeliveries())->toBeTrue()
        ->and($source->fresh()->writesRecords())->toBeFalse();

    $screen->call('toggleSandbox', $source->id);
    expect($source->fresh()->is_sandbox)->toBeFalse()
        ->and($source->fresh()->writesRecords())->toBeTrue();
});

test('removing a source from the list keeps its deliveries', function () {
    $source = DataSource::factory()->create();
    $event = IntegrationEvent::factory()->forSource($source)->create();

    Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->call('delete', $source->id);

    expect(DataSource::query()->whereKey($source->id)->exists())->toBeFalse()
        ->and(IntegrationEvent::query()->whereKey($event->id)->exists())->toBeTrue();
});

// -- Admin-only access ---------------------------------------------------------

test('somebody who may only view is not offered the controls', function () {
    $viewer = ingestionUser(['integrations.view']);
    $source = DataSource::factory()->create(['name' => 'Delivery tool']);

    $screen = Livewire::actingAs($viewer)->test(DataSources::class);

    expect($screen->instance()->canManage())->toBeFalse();

    $screen->assertSee('Delivery tool')
        ->assertDontSeeHtml('wire:click="add"')
        ->assertDontSeeHtml('wire:click="edit('.$source->id.')"')
        ->assertDontSeeHtml('wire:click="toggleActive('.$source->id.')"');
});

test('a viewer who calls a management method anyway is refused', function (string $method) {
    // The screen not rendering a button is not a permission check; every one of
    // these is authorised server-side as well.
    $source = DataSource::factory()->create();

    Livewire::actingAs(ingestionUser(['integrations.view']))
        ->test(DataSources::class)
        ->call($method, $source->id)
        ->assertForbidden();

    expect($source->fresh()->is_active)->toBeTrue()
        ->and($source->fresh()->is_sandbox)->toBeFalse()
        ->and(DataSource::query()->whereKey($source->id)->exists())->toBeTrue();
})->with(['edit', 'toggleActive', 'toggleSandbox', 'delete']);

test('a viewer cannot open the create form or save through it', function () {
    Livewire::actingAs(ingestionUser(['integrations.view']))
        ->test(DataSources::class)
        ->call('add')
        ->assertForbidden();

    Livewire::actingAs(ingestionUser(['integrations.view']))
        ->test(DataSources::class)
        ->set('name', 'Sneaky')
        ->set('target_module', 'leads')
        ->call('save')
        ->assertForbidden();

    expect(DataSource::query()->count())->toBe(0);
});

// -- What the screen shows -----------------------------------------------------

test('a push source shows the address to post to', function () {
    $source = DataSource::factory()->create();

    Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->assertSee($source->ingestUrl());
});

test('a pull source has no address to show, because nothing posts to it', function () {
    $source = DataSource::factory()->pull()->create();

    Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->assertDontSee($source->ingestUrl());
});

test('the list says how a source is set up without anybody opening it', function () {
    DataSource::factory()->sandbox()->disabled()->into('contacts')->create(['name' => 'Half built']);

    Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->assertSee('Half built')
        ->assertSee('Sandbox')
        ->assertSee('Contacts')
        ->assertSee('Off');
});

test('the list counts deliveries without loading them', function () {
    $source = DataSource::factory()->create();
    IntegrationEvent::factory()->forSource($source)->count(3)->create();

    $sources = Livewire::actingAs(ingestionAdmin())->test(DataSources::class)->instance()->sources();

    expect($sources->firstOrFail()->events_count)->toBe(3);
});

test('an empty screen says what a source is for', function () {
    Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->assertSee('No data sources yet');
});
