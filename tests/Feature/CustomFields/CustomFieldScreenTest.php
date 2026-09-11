<?php

use App\Domain\CustomFields\Enums\CustomFieldType;
use App\Domain\CustomFields\Models\CustomField;
use App\Domain\CustomFields\Models\CustomFieldValue;
use App\Domain\Leads\Models\Lead;
use App\Domain\Settings\SettingsNavigation;
use App\Livewire\CustomFields\CustomFieldsIndex;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
});

test('a guest is sent to sign in', function () {
    $this->get(route('settings.custom-fields'))->assertRedirect(route('login'));
});

test('the screen needs the view permission', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.custom-fields'))
        ->assertForbidden();

    $this->actingAs(customFieldUser(['custom-fields.view']))
        ->get(route('settings.custom-fields'))
        ->assertOk();
});

test('the settings navigation offers it only to somebody who may see it', function () {
    $labels = collect(SettingsNavigation::for(customFieldUser(['custom-fields.view'])))
        ->flatMap(fn (array $section) => array_column($section['items'], 'label'));

    expect($labels)->toContain('Custom fields');

    $without = collect(SettingsNavigation::for(User::factory()->create()))
        ->flatMap(fn (array $section) => array_column($section['items'], 'label'));

    expect($without)->not->toContain('Custom fields');
});

test('somebody who may only look is offered nothing to press', function () {
    leadField(CustomFieldType::Text, 'Sector');

    Livewire::actingAs(customFieldUser(['custom-fields.view']))
        ->test(CustomFieldsIndex::class)
        ->assertOk()
        ->assertSee('Sector')
        ->assertDontSee('Add field')
        ->assertDontSee('Remove');
});

test('creating a field from the screen derives its key', function () {
    Livewire::actingAs(customFieldUser())
        ->test(CustomFieldsIndex::class)
        ->call('add')
        ->set('label', 'Industry sector')
        ->set('type', 'text')
        ->call('save')
        ->assertHasNoErrors();

    expect(CustomField::query()->where('module', 'leads')->value('key'))->toBe('industry_sector');
});

test('the form insists on a label', function () {
    Livewire::actingAs(customFieldUser())
        ->test(CustomFieldsIndex::class)
        ->call('add')
        ->set('label', '')
        ->call('save')
        ->assertHasErrors('label');
});

test('a dropdown with no choices is refused', function () {
    Livewire::actingAs(customFieldUser())
        ->test(CustomFieldsIndex::class)
        ->call('add')
        ->set('label', 'Sector')
        ->set('type', 'select')
        ->set('options', [])
        ->call('save')
        ->assertHasErrors('options');
});

test('a lookup must say which module it points at', function () {
    Livewire::actingAs(customFieldUser())
        ->test(CustomFieldsIndex::class)
        ->call('add')
        ->set('label', 'Parent account')
        ->set('type', 'lookup')
        ->set('lookupModule', '')
        ->call('save')
        ->assertHasErrors('lookupModule');
});

test('a module the registry does not list is refused by validation', function () {
    Livewire::actingAs(customFieldUser())
        ->test(CustomFieldsIndex::class)
        ->call('add')
        ->set('label', 'Parent')
        ->set('type', 'lookup')
        ->set('lookupModule', 'invoices')
        ->call('save')
        ->assertHasErrors('lookupModule');
});

test('changing the type clears what the previous one carried', function () {
    // Switching a dropdown to a text field and back would otherwise keep
    // options nobody can see.
    Livewire::actingAs(customFieldUser())
        ->test(CustomFieldsIndex::class)
        ->call('add')
        ->set('type', 'select')
        ->call('addOption')
        ->set('options.0.label', 'Retail')
        ->set('type', 'text')
        ->assertSet('options', [])
        ->set('type', 'lookup')
        ->set('lookupModule', 'accounts')
        ->set('type', 'text')
        ->assertSet('lookupModule', '');
});

test('the module selector switches the list, and one it does not offer is ignored', function () {
    leadField(CustomFieldType::Text, 'On leads');

    Livewire::actingAs(customFieldUser())
        ->test(CustomFieldsIndex::class)
        ->assertSee('On leads')
        ->call('selectModule', 'deals')
        ->assertSet('module', 'deals')
        ->assertDontSee('On leads')
        ->call('selectModule', 'invoices')
        ->assertSet('module', 'deals');
});

test('a field on another module cannot be edited or removed from this one', function () {
    $lead = leadField(CustomFieldType::Text, 'On leads');

    $screen = Livewire::actingAs(customFieldUser())
        ->test(CustomFieldsIndex::class)
        ->call('selectModule', 'deals')
        ->call('edit', $lead->id)
        ->assertSet('editing', false);

    $screen->call('delete', $lead->id);

    expect(CustomField::query()->whereKey($lead->id)->exists())->toBeTrue();
});

test('hiding a field keeps its answers, removing it does not', function () {
    $field = leadField(CustomFieldType::Text, 'Sector');
    Lead::factory()->create()->saveCustomFields([$field->key => 'Manufacturing']);

    $screen = Livewire::actingAs(customFieldUser())
        ->test(CustomFieldsIndex::class)
        ->call('toggleActive', $field->id);

    expect($field->fresh()->is_active)->toBeFalse()
        ->and(CustomFieldValue::query()->count())->toBe(1);

    $screen->call('delete', $field->id);

    expect(CustomField::query()->count())->toBe(0)
        ->and(CustomFieldValue::query()->count())->toBe(0);
});

test('the screen says how many answers a field holds', function () {
    $field = leadField(CustomFieldType::Text, 'Sector');
    Lead::factory()->create()->saveCustomFields([$field->key => 'Manufacturing']);
    Lead::factory()->create()->saveCustomFields([$field->key => 'Retail']);

    Livewire::actingAs(customFieldUser())
        ->test(CustomFieldsIndex::class)
        ->assertSee('2 answers');
});

test('fields can be moved up and down, and not off the ends', function () {
    $one = leadField(CustomFieldType::Text, 'One');
    $two = leadField(CustomFieldType::Text, 'Two');

    $screen = Livewire::actingAs(customFieldUser())
        ->test(CustomFieldsIndex::class)
        ->call('moveDown', $one->id);

    expect(CustomField::query()->forModule('leads')->ordered()->pluck('id')->all())->toBe([$two->id, $one->id]);

    // Already at the top, so this is a no-op rather than an error.
    $screen->call('moveUp', $two->id);

    expect(CustomField::query()->forModule('leads')->ordered()->pluck('id')->all())->toBe([$two->id, $one->id]);
});

test('editing loads what is stored, including the choices', function () {
    $field = leadField(CustomFieldType::Select, 'Sector', options: ['Retail', 'Wholesale']);

    Livewire::actingAs(customFieldUser())
        ->test(CustomFieldsIndex::class)
        ->call('edit', $field->id)
        ->assertSet('editing', true)
        ->assertSet('label', 'Sector')
        ->assertSet('type', 'select')
        ->assertSet('options', $field->options);
});

test('somebody who may only look cannot create or remove by calling the methods', function () {
    $field = leadField(CustomFieldType::Text, 'Sector');

    Livewire::actingAs(customFieldUser(['custom-fields.view']))
        ->test(CustomFieldsIndex::class)
        ->call('add')
        ->assertForbidden();

    Livewire::actingAs(customFieldUser(['custom-fields.view']))
        ->test(CustomFieldsIndex::class)
        ->call('delete', $field->id)
        ->assertForbidden();

    expect(CustomField::query()->count())->toBe(1);
});
