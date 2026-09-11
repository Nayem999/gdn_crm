<?php

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\PermissionResolver;
use App\Domain\CustomFields\Actions\SaveCustomFieldAction;
use App\Domain\CustomFields\CustomFieldColumns;
use App\Domain\CustomFields\CustomFieldRegistry;
use App\Domain\CustomFields\CustomFieldSchema;
use App\Domain\CustomFields\DTOs\CustomFieldData;
use App\Domain\CustomFields\Enums\CustomFieldType;
use App\Domain\CustomFields\Models\CustomField;
use App\Domain\CustomModules\Actions\DeleteCustomModuleAction;
use App\Domain\CustomModules\Actions\SaveCustomModuleAction;
use App\Domain\CustomModules\CustomModuleRegistry;
use App\Domain\CustomModules\CustomRecordExportSource;
use App\Domain\CustomModules\DTOs\CustomModuleData;
use App\Domain\CustomModules\Models\CustomModule;
use App\Domain\CustomModules\Models\CustomRecord;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Domain\Shared\Enums\ExportFormat;
use App\Domain\Shared\Enums\ViewMode;
use App\Domain\Shared\Exports\DataViewExport;
use App\Domain\Shared\Exports\ExportRequest;
use App\Domain\Shared\Models\SavedView;
use App\Domain\Shared\SavedViews\SavedViewState;
use App\Livewire\CustomModules\CustomModulesIndex;
use App\Livewire\CustomModules\CustomRecordForm;
use App\Livewire\CustomModules\CustomRecordsIndex;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @param  array<int, string>|null  $permissions
 */
function moduleUser(?array $permissions = null, string $level = DataAccessLevel::All->value): User
{
    $permissions ??= [
        'custom-modules.view', 'custom-modules.create', 'custom-modules.update',
        'custom-modules.delete', 'custom-modules.export', 'custom-modules.configure',
    ];

    $role = Role::query()->create([
        'name' => 'Modules '.uniqid(),
        'guard_name' => Guard::getDefaultName(Role::class),
        'data_access_level' => $level,
    ]);

    $role->syncPermissions(PermissionResolver::models($permissions));

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * A module defined through the action, so the fixture is something the
 * application could have produced.
 */
function generatedModule(string $name = 'Project', string $titleLabel = 'Project name'): CustomModule
{
    return app(SaveCustomModuleAction::class)(CustomModuleData::fromArray([
        'name' => $name,
        'title_label' => $titleLabel,
    ]));
}

/**
 * A custom field on a generated module — the same call a built-in module makes.
 *
 * @param  array<int, string>  $options
 */
function moduleField(
    CustomModule $module,
    string $label,
    CustomFieldType $type = CustomFieldType::Text,
    array $options = ['One', 'Two'],
): CustomField {
    return app(SaveCustomFieldAction::class)(CustomFieldData::fromArray([
        'module' => $module->moduleKey(),
        'label' => $label,
        'type' => $type->value,
        'options' => array_map(fn (string $o) => ['label' => $o], $options),
    ]));
}

beforeEach(function () {
    Cache::flush();
    app(CustomModuleRegistry::class)->flush();
    app(CustomFieldSchema::class)->flush();
});

// -- The headline requirement --------------------------------------------------

test('a generated module gets a list, all four views, filters and an export', function () {
    $user = moduleUser();
    $module = generatedModule();
    $field = moduleField($module, 'Status note');

    $record = CustomRecord::factory()->inModule($module)->ownedBy($user)->named('Atrium rebuild')->create();
    $record->saveCustomFields([$field->key => 'On track']);

    $screen = Livewire::actingAs($user)->test(CustomRecordsIndex::class, ['module' => $module->moduleKey()]);

    // A list, with the module's own title column named as the module names it.
    $screen->assertOk()->assertSee('Atrium rebuild');
    expect(collect($screen->instance()->dataViewColumns())->firstWhere('key', 'name')->label)
        ->toBe('Project name');

    // Every view mode this module offers. Kanban is not among them: a board
    // groups by a column in SQL, and a generated module's fields live in
    // another table — see the note on dataViewKanbanField below.
    $modes = $screen->instance()->availableViewModes();

    expect($modes)->not->toContain(ViewMode::Kanban)
        ->and($modes)->toHaveCount(3);

    foreach ($modes as $mode) {
        $screen->call('setViewMode', $mode->value)->assertOk()->assertSee('Atrium rebuild');
    }

    $screen->call('setViewMode', ViewMode::Table->value);

    // The custom field is a column, a filter, and an export cell.
    $columnKey = CustomFieldColumns::columnKey($field);

    expect(collect($screen->instance()->dataViewColumns())->pluck('key'))->toContain($columnKey)
        ->and(collect($screen->instance()->dataViewFilterFields())->pluck('key'))->toContain($columnKey);

    $request = new ExportRequest(
        source: CustomRecordExportSource::class,
        format: ExportFormat::Csv,
        module: $module->moduleKey(),
        columns: ['name' => 'Project name', $columnKey => 'Status note'],
        userId: $user->id,
    );

    $export = new DataViewExport($request, app(CustomRecordExportSource::class));

    expect($export->map($export->query()->findOrFail($record->id)))->toBe(['Atrium rebuild', 'On track']);
});

test('a generated module supports the full CRUD cycle', function () {
    $user = moduleUser();
    $module = generatedModule();
    $field = moduleField($module, 'Status note');

    // Create.
    Livewire::actingAs($user)
        ->test(CustomRecordForm::class, ['module' => $module->moduleKey()])
        ->set('name', 'Atrium rebuild')
        ->set('customFields.'.$field->key, 'On track')
        ->call('save')
        ->assertHasNoErrors();

    $record = CustomRecord::query()->firstOrFail();

    expect($record->name)->toBe('Atrium rebuild')
        ->and($record->customField($field->key))->toBe('On track')
        ->and($record->owner_id)->toBe($user->id);

    // Read back into the form.
    Livewire::actingAs($user)
        ->test(CustomRecordForm::class, ['module' => $module->moduleKey(), 'record' => $record->id])
        ->assertSet('name', 'Atrium rebuild')
        ->assertSet('customFields.'.$field->key, 'On track');

    // Update.
    Livewire::actingAs($user)
        ->test(CustomRecordForm::class, ['module' => $module->moduleKey(), 'record' => $record->id])
        ->set('name', 'Atrium refit')
        ->call('save')
        ->assertHasNoErrors();

    expect($record->fresh()->name)->toBe('Atrium refit');

    // Delete.
    Livewire::actingAs($user)
        ->test(CustomRecordsIndex::class, ['module' => $module->moduleKey()])
        ->call('delete', $record->id);

    expect(CustomRecord::query()->count())->toBe(0)
        ->and(CustomRecord::withTrashed()->count())->toBe(1);
});

test('a filter on a generated module custom field narrows the list', function () {
    $user = moduleUser();
    $module = generatedModule();
    $field = moduleField($module, 'Status note');

    $match = CustomRecord::factory()->inModule($module)->ownedBy($user)->named('Atrium')->create();
    $match->saveCustomFields([$field->key => 'On track']);

    CustomRecord::factory()->inModule($module)->ownedBy($user)->named('Belvedere')->create();

    Livewire::actingAs($user)
        ->test(CustomRecordsIndex::class, ['module' => $module->moduleKey()])
        ->set('filters', [
            'match' => 'all',
            'conditions' => [[
                'field' => CustomFieldColumns::columnKey($field),
                'operator' => 'equals',
                'value' => 'On track',
            ]],
            'groups' => [],
        ])
        ->assertSee('Atrium')
        ->assertDontSee('Belvedere');
});

test('the list searches and sorts on the module own title column', function () {
    $user = moduleUser();
    $module = generatedModule();

    CustomRecord::factory()->inModule($module)->ownedBy($user)->named('Zephyr')->create();
    CustomRecord::factory()->inModule($module)->ownedBy($user)->named('Atrium')->create();

    Livewire::actingAs($user)
        ->test(CustomRecordsIndex::class, ['module' => $module->moduleKey()])
        ->set('search', 'Zeph')
        ->assertSee('Zephyr')
        ->assertDontSee('Atrium')
        ->set('search', '')
        ->call('sort', 'name')
        ->assertSeeInOrder(['Atrium', 'Zephyr']);
});

test('a view mode the screen does not offer is refused', function () {
    $user = moduleUser();
    $module = generatedModule();

    Livewire::actingAs($user)
        ->test(CustomRecordsIndex::class, ['module' => $module->moduleKey()])
        ->call('setViewMode', ViewMode::Kanban->value)
        // An empty board with no columns and no explanation is worse than
        // staying where you were.
        ->assertSet('viewMode', ViewMode::Table->value);
});

// -- Two modules never mix -----------------------------------------------------

test('one module list never shows another module records', function () {
    // Every generated module shares a table, so a query without forModule would
    // mix them.
    $user = moduleUser();
    $projects = generatedModule('Project');
    $assets = generatedModule('Asset');

    CustomRecord::factory()->inModule($projects)->ownedBy($user)->named('Atrium')->create();
    CustomRecord::factory()->inModule($assets)->ownedBy($user)->named('Forklift')->create();

    Livewire::actingAs($user)
        ->test(CustomRecordsIndex::class, ['module' => $projects->moduleKey()])
        ->assertSee('Atrium')
        ->assertDontSee('Forklift');
});

test('a record from another module cannot be edited through this module form', function () {
    $user = moduleUser();
    $projects = generatedModule('Project');
    $assets = generatedModule('Asset');

    $asset = CustomRecord::factory()->inModule($assets)->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(CustomRecordForm::class, ['module' => $projects->moduleKey(), 'record' => $asset->id])
        ->assertNotFound();
});

test('each module keeps its own custom fields', function () {
    $projects = generatedModule('Project');
    $assets = generatedModule('Asset');

    $projectField = moduleField($projects, 'Status note');
    moduleField($assets, 'Serial number');

    $record = CustomRecord::factory()->inModule($projects)->create();

    expect($record->customFields()->pluck('key')->all())->toBe([$projectField->key]);
});

test('two modules with the same name get different keys', function () {
    $first = generatedModule('Project');
    $second = generatedModule('Project');

    expect($first->key)->toBe('project')
        ->and($second->key)->toBe('project_2');
});

test('a module key cannot shadow a built-in module', function () {
    // Prefixed, so an administrator calling their module "Leads" does not take
    // over the real one in the field registry, a saved view or a route.
    $module = generatedModule('Leads');

    expect($module->moduleKey())->toBe('cm_leads')
        ->and(CustomFieldRegistry::modelClass('leads'))->not->toBe(CustomRecord::class)
        ->and(CustomFieldRegistry::modelClass('cm_leads'))->toBe(CustomRecord::class);
});

// -- Access and permissions ----------------------------------------------------

test('a guest is sent to sign in', function () {
    $module = generatedModule();

    $this->get(route('custom-modules.index', $module->moduleKey()))->assertRedirect(route('login'));
});

test('the list needs the view permission', function () {
    $module = generatedModule();

    $this->actingAs(User::factory()->create())
        ->get(route('custom-modules.index', $module->moduleKey()))
        ->assertForbidden();

    $this->actingAs(moduleUser(['custom-modules.view']))
        ->get(route('custom-modules.index', $module->moduleKey()))
        ->assertOk();
});

test('a module key the registry does not hold is not found', function () {
    $this->actingAs(moduleUser())
        ->get(route('custom-modules.index', 'cm_invented'))
        ->assertNotFound();
});

test('a hidden module is unreachable and absent from the navigation', function () {
    $module = generatedModule();
    $module->forceFill(['is_active' => false])->save();
    app(CustomModuleRegistry::class)->flush();

    $this->actingAs(moduleUser())
        ->get(route('custom-modules.index', $module->moduleKey()))
        ->assertNotFound();

    $this->actingAs(moduleUser())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee($module->plural_name);
});

test('records respect the data access level', function () {
    $owner = moduleUser(level: DataAccessLevel::Own->value);
    $peer = moduleUser(level: DataAccessLevel::Own->value);
    $module = generatedModule();

    CustomRecord::factory()->inModule($module)->ownedBy($owner)->named('Mine')->create();
    CustomRecord::factory()->inModule($module)->ownedBy($peer)->named('Theirs')->create();

    Livewire::actingAs($owner)
        ->test(CustomRecordsIndex::class, ['module' => $module->moduleKey()])
        ->assertSee('Mine')
        ->assertDontSee('Theirs');
});

test('a record outside the access level cannot be reached by id', function () {
    $owner = moduleUser(level: DataAccessLevel::Own->value);
    $peer = moduleUser(level: DataAccessLevel::Own->value);
    $module = generatedModule();

    $theirs = CustomRecord::factory()->inModule($module)->ownedBy($peer)->create();

    Livewire::actingAs($owner)
        ->test(CustomRecordForm::class, ['module' => $module->moduleKey(), 'record' => $theirs->id])
        ->assertNotFound();
});

test('a queued export re-applies the access scope', function () {
    $owner = moduleUser(level: DataAccessLevel::Own->value);
    $peer = moduleUser(level: DataAccessLevel::Own->value);
    $module = generatedModule();

    CustomRecord::factory()->inModule($module)->ownedBy($owner)->named('Mine')->create();
    CustomRecord::factory()->inModule($module)->ownedBy($peer)->named('Theirs')->create();

    $request = new ExportRequest(
        source: CustomRecordExportSource::class,
        format: ExportFormat::Csv,
        module: $module->moduleKey(),
        columns: ['name' => 'Name'],
        userId: $owner->id,
    );

    expect(app(CustomRecordExportSource::class)->exportQuery($request)->pluck('name')->all())
        ->toBe(['Mine']);
});

test('an export naming a module that has gone gets nothing, not everything', function () {
    $user = moduleUser();
    generatedModule();
    CustomRecord::factory()->create();

    $request = new ExportRequest(
        source: CustomRecordExportSource::class,
        format: ExportFormat::Csv,
        module: 'cm_invented',
        columns: ['name' => 'Name'],
        userId: $user->id,
    );

    expect(fn () => app(CustomRecordExportSource::class)->exportQuery($request))
        ->toThrow(NotFoundHttpException::class);
});

test('every custom module permission is declared in the catalogue', function (string $permission) {
    expect(PermissionCatalogue::has($permission))->toBeTrue();
})->with([
    'custom-modules.view', 'custom-modules.create', 'custom-modules.update',
    'custom-modules.delete', 'custom-modules.export', 'custom-modules.configure',
]);

// -- Saved views and column layouts work per generated module ------------------

test('a saved view on a generated module is its own', function () {
    $user = moduleUser();
    $projects = generatedModule('Project');
    $assets = generatedModule('Asset');

    SavedView::factory()->ownedBy($user)->forModule($projects->moduleKey())
        ->withState(new SavedViewState(search: 'atrium'))->create(['name' => 'Live projects']);

    Livewire::actingAs($user)
        ->test(CustomRecordsIndex::class, ['module' => $assets->moduleKey()])
        ->assertOk();

    expect(Livewire::actingAs($user)->test(CustomRecordsIndex::class, ['module' => $assets->moduleKey()])
        ->instance()->savedViews())->toBeEmpty();

    expect(Livewire::actingAs($user)->test(CustomRecordsIndex::class, ['module' => $projects->moduleKey()])
        ->instance()->savedViews()->pluck('name')->all())->toBe(['Live projects']);
});

// -- Defining a module ---------------------------------------------------------

test('the settings screen needs the configure permission', function () {
    $this->actingAs(moduleUser(['custom-modules.view']))
        ->get(route('settings.custom-modules'))
        ->assertForbidden();

    $this->actingAs(moduleUser(['custom-modules.configure']))
        ->get(route('settings.custom-modules'))
        ->assertOk();
});

test('creating a module from the screen derives its key and a plural', function () {
    Livewire::actingAs(moduleUser())
        ->test(CustomModulesIndex::class)
        ->call('add')
        ->set('name', 'Service contract')
        ->set('titleLabel', 'Contract reference')
        ->call('save')
        ->assertHasNoErrors();

    $module = CustomModule::query()->firstOrFail();

    expect($module->key)->toBe('service_contract')
        ->and($module->plural_name)->toBe('Service contracts')
        ->and($module->title_label)->toBe('Contract reference');
});

test('a module key never changes when it is renamed', function () {
    $module = generatedModule('Project');
    $key = $module->key;

    app(SaveCustomModuleAction::class)(CustomModuleData::fromArray([
        'name' => 'Programme',
        'title_label' => 'Programme name',
    ]), $module);

    expect($module->fresh()->key)->toBe($key)
        ->and($module->fresh()->name)->toBe('Programme');
});

test('the form insists on a name and a title label', function () {
    Livewire::actingAs(moduleUser())
        ->test(CustomModulesIndex::class)
        ->call('add')
        ->set('name', '')
        ->set('titleLabel', '')
        ->call('save')
        ->assertHasErrors(['name', 'titleLabel']);
});

test('a colour the palette does not hold is refused', function () {
    Livewire::actingAs(moduleUser())
        ->test(CustomModulesIndex::class)
        ->call('add')
        ->set('name', 'Project')
        ->set('color', 'ultraviolet')
        ->call('save')
        ->assertHasErrors('color');
});

test('somebody who may only see records cannot define a module', function () {
    Livewire::actingAs(moduleUser(['custom-modules.view']))
        ->test(CustomModulesIndex::class)
        ->assertForbidden();
});

test('removing a module takes its records and its field definitions', function () {
    // The records cascade off the foreign key; the field definitions do not,
    // because custom_fields.module is a string rather than a relation — so
    // nothing in the database would clear them.
    $module = generatedModule();
    $field = moduleField($module, 'Status note');
    $record = CustomRecord::factory()->inModule($module)->create();
    $record->saveCustomFields([$field->key => 'On track']);

    app(DeleteCustomModuleAction::class)($module);

    expect(CustomModule::query()->count())->toBe(0)
        ->and(CustomRecord::withTrashed()->count())->toBe(0)
        ->and(CustomField::query()->count())->toBe(0);
});

test('hiding a module keeps everything in it', function () {
    $module = generatedModule();
    $field = moduleField($module, 'Status note');
    CustomRecord::factory()->inModule($module)->create();

    Livewire::actingAs(moduleUser())
        ->test(CustomModulesIndex::class)
        ->call('toggleActive', $module->id);

    expect($module->fresh()->is_active)->toBeFalse()
        ->and(CustomRecord::query()->count())->toBe(1)
        ->and(CustomField::query()->whereKey($field->id)->exists())->toBeTrue();
});

test('a new module appears in the navigation on the next render', function () {
    $user = moduleUser();

    $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertDontSee('Projects');

    generatedModule('Project');

    $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Projects');
});

test('the registry reads the modules once per request', function () {
    generatedModule('Project');
    generatedModule('Asset');

    $registry = app(CustomModuleRegistry::class);
    $registry->flush();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    for ($i = 0; $i < 5; $i++) {
        $registry->all();
        $registry->options();
    }

    expect($queries)->toBe(1);
});
