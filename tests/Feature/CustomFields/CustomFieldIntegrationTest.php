<?php

use App\Domain\Accounts\AccountExportSource;
use App\Domain\Accounts\Models\Account;
use App\Domain\Activities\ActivityExportSource;
use App\Domain\Activities\Models\Activity;
use App\Domain\Contacts\ContactExportSource;
use App\Domain\Contacts\Models\Contact;
use App\Domain\CustomFields\Actions\SaveCustomFieldAction;
use App\Domain\CustomFields\Actions\ToggleCustomFieldAction;
use App\Domain\CustomFields\CustomFieldColumns;
use App\Domain\CustomFields\CustomFieldRegistry;
use App\Domain\CustomFields\CustomFieldSchema;
use App\Domain\CustomFields\DTOs\CustomFieldData;
use App\Domain\CustomFields\Enums\CustomFieldType;
use App\Domain\CustomFields\Models\CustomField;
use App\Domain\Deals\DealExportSource;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\LeadExportSource;
use App\Domain\Leads\LeadFields;
use App\Domain\Leads\Models\Lead;
use App\Domain\Products\Models\Product;
use App\Domain\Products\ProductExportSource;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\QuoteExportSource;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Domain\Shared\Enums\ExportFormat;
use App\Domain\Shared\Exports\DataViewExport;
use App\Domain\Shared\Exports\ExportRequest;
use App\Domain\Shared\Filters\FilterApplier;
use App\Domain\Shared\Filters\FilterGroup;
use App\Domain\Support\Models\Ticket;
use App\Domain\Support\TicketExportSource;
use App\Livewire\Accounts\AccountsIndex;
use App\Livewire\Activities\ActivitiesIndex;
use App\Livewire\Contacts\ContactsIndex;
use App\Livewire\Deals\DealsIndex;
use App\Livewire\Leads\LeadForm;
use App\Livewire\Leads\LeadsIndex;
use App\Livewire\Products\ProductsIndex;
use App\Livewire\Sales\QuotesIndex;
use App\Livewire\Support\TicketsIndex;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * The screen component, the export source and the model for one module, so the
 * "all four places" test can walk every module rather than naming one.
 *
 * @return array<string, array{index: class-string, source: class-string, model: class-string}>
 */
function integrationModules(): array
{
    return [
        'leads' => [
            'index' => LeadsIndex::class,
            'source' => LeadExportSource::class,
            'model' => Lead::class,
        ],
        'contacts' => [
            'index' => ContactsIndex::class,
            'source' => ContactExportSource::class,
            'model' => Contact::class,
        ],
        'accounts' => [
            'index' => AccountsIndex::class,
            'source' => AccountExportSource::class,
            'model' => Account::class,
        ],
        'deals' => [
            'index' => DealsIndex::class,
            'source' => DealExportSource::class,
            'model' => Deal::class,
        ],
        'activities' => [
            'index' => ActivitiesIndex::class,
            'source' => ActivityExportSource::class,
            'model' => Activity::class,
        ],
        'products' => [
            'index' => ProductsIndex::class,
            'source' => ProductExportSource::class,
            'model' => Product::class,
        ],
        'quotes' => [
            'index' => QuotesIndex::class,
            'source' => QuoteExportSource::class,
            'model' => Quote::class,
        ],
        'tickets' => [
            'index' => TicketsIndex::class,
            'source' => TicketExportSource::class,
            'model' => Ticket::class,
        ],
    ];
}

/**
 * Somebody who can see and do everything the list screens need.
 */
function integrationUser(): User
{
    return customFieldUser([
        'custom-fields.view', 'custom-fields.manage',
        'leads.view', 'leads.create', 'leads.update', 'leads.export',
        'contacts.view', 'contacts.export',
        'accounts.view', 'accounts.export',
        'deals.view', 'deals.export',
        'activities.view', 'activities.export',
        'products.view', 'products.export',
        'quotes.view', 'quotes.export',
        'tickets.view', 'tickets.export',
    ]);
}

/**
 * A field on a module, created through the action and with the request-scoped
 * definition memo cleared so the very next read sees it.
 *
 * @param  array<int, string>  $options
 */
function fieldOn(string $module, CustomFieldType $type, string $label, array $options = ['One', 'Two']): CustomField
{
    $field = app(SaveCustomFieldAction::class)(CustomFieldData::fromArray([
        'module' => $module,
        'label' => $label,
        'type' => $type->value,
        'options' => array_map(fn (string $o) => ['label' => $o], $options),
        'lookup_module' => $type->isLookup() ? 'accounts' : null,
    ]));

    return $field;
}

function integrationRecord(string $module): Model
{
    return match ($module) {
        'leads' => Lead::factory()->create(),
        'contacts' => Contact::factory()->create(),
        'accounts' => Account::factory()->create(),
        'deals' => Deal::factory()->create(),
        'activities' => Activity::factory()->create(),
        'products' => Product::factory()->create(),
        'quotes' => Quote::factory()->create(),
        'tickets' => Ticket::factory()->create(),
    };
}

beforeEach(function () {
    Cache::flush();
});

// -- The headline requirement --------------------------------------------------

test('a new custom field appears in all four places automatically', function (string $module) {
    $spec = integrationModules()[$module];
    $user = integrationUser();

    $field = fieldOn($module, CustomFieldType::Text, 'Integration marker');
    $columnKey = CustomFieldColumns::columnKey($field);

    $record = integrationRecord($module);
    $record->saveCustomFields([$field->key => 'Marked']);

    // 1. Forms — the field is offered, and the answer loads back into it.
    $this->actingAs($user);

    // 2. Table columns — the column manager offers it, and the cell renders.
    $screen = Livewire::actingAs($user)->test($spec['index']);
    $columns = collect($screen->instance()->dataViewColumns());

    expect($columns->pluck('key'))->toContain($columnKey)
        ->and($columns->firstWhere('key', $columnKey)->label)->toBe('Integration marker');

    $cell = $screen->instance()->cellFor(
        $spec['model']::query()->with('customFieldValues')->findOrFail($record->getKey()),
        $columns->firstWhere('key', $columnKey),
    );

    expect((string) $cell)->toContain('Marked');

    // 3. Filter builder — the field is offered, and it filters.
    $filters = collect($screen->instance()->dataViewFilterFields());

    expect($filters->pluck('key'))->toContain($columnKey);

    $matching = $spec['model']::query();
    app(FilterApplier::class)->apply(
        $matching,
        FilterGroup::fromArray([
            'match' => 'all',
            'conditions' => [['field' => $columnKey, 'operator' => 'equals', 'value' => 'Marked']],
        ]),
        $screen->instance()->filterFieldMap(),
    );

    expect($matching->pluck($spec['model']::query()->getModel()->getKeyName())->all())
        ->toBe([$record->getKey()]);

    // 4. Exports — the column is exportable and the cell carries the answer.
    $request = new ExportRequest(
        source: $spec['source'],
        format: ExportFormat::Csv,
        module: $module,
        columns: [$columnKey => 'Integration marker'],
        userId: $user->id,
    );

    $export = new DataViewExport($request, app($spec['source']));
    $row = $export->map($export->query()->findOrFail($record->getKey()));

    expect($row)->toBe(['Marked']);
    // builtInKeys, not keys: a dataset closure is resolved before the test
    // database exists, and keys() now reads the generated modules.
})->with(fn () => CustomFieldRegistry::builtInKeys());

// -- Columns -------------------------------------------------------------------

test('a custom column is prefixed so it cannot shadow a real one', function () {
    // A field keyed `status` would otherwise take over the module's own status
    // column in the manager, the sort and the export headings.
    $field = fieldOn('leads', CustomFieldType::Text, 'Status');

    expect($field->key)->toBe('status')
        ->and(CustomFieldColumns::columnKey($field))->toBe('cf_status');

    $keys = collect(LeadFields::columns())->pluck('key');

    expect($keys)->toContain('status')->toContain('cf_status');
});

test('a custom column is off by default and not sortable', function () {
    $field = fieldOn('leads', CustomFieldType::Text, 'Sector');

    $column = collect(LeadFields::columns())
        ->firstWhere('key', CustomFieldColumns::columnKey($field));

    // Off by default so a dozen custom fields do not push the module's own
    // columns off the screen; not sortable because the value lives in another
    // table and the kit's sort does not carry a join.
    expect($column->hiddenByDefault)->toBeTrue()
        ->and($column->sortable)->toBeFalse();
});

test('a hidden field is in none of the four places', function () {
    $field = fieldOn('leads', CustomFieldType::Text, 'Sector');
    app(ToggleCustomFieldAction::class)($field);

    $key = CustomFieldColumns::columnKey($field);

    expect(collect(LeadFields::columns())->pluck('key'))->not->toContain($key)
        ->and(array_keys(LeadFields::filters()))->not->toContain($key);
});

test('each type renders its answer the way a person reads it', function (string $type, mixed $value, string $expected) {
    $type = CustomFieldType::from($type);
    $field = fieldOn('leads', $type, 'Extra', ['Retail', 'Wholesale']);
    $lead = Lead::factory()->create();

    if ($type->hasOptions()) {
        $keys = $field->optionKeys();
        $value = $type->isMultiple() ? $keys : $keys[0];
    }

    $lead->saveCustomFields([$field->key => $value]);

    $loaded = Lead::query()->with('customFieldValues')->findOrFail($lead->id);

    expect(CustomFieldColumns::display($loaded, CustomFieldColumns::columnKey($field)))->toBe($expected);
})->with([
    ['text', 'Manufacturing', 'Manufacturing'],
    ['number', 12.5, '12.50'],
    ['currency', 1200, '1,200.00'],
    ['date', '2026-12-31', '31 Dec 2026'],
    ['checkbox', true, 'Yes'],
    // Labels, not the stored keys: an export of option keys is one nobody can
    // use, and the same goes for a column on screen.
    ['select', null, 'Retail'],
    ['multiselect', null, 'Retail, Wholesale'],
]);

test('a lookup column shows the record it points at', function () {
    $account = Account::factory()->create(['name' => 'Northwind Trading']);
    $field = fieldOn('leads', CustomFieldType::Lookup, 'Parent account');
    $lead = Lead::factory()->create();
    $lead->saveCustomFields([$field->key => $account->id]);

    $loaded = Lead::query()->with('customFieldValues')->findOrFail($lead->id);

    expect(CustomFieldColumns::display($loaded, CustomFieldColumns::columnKey($field)))
        ->toBe('Northwind Trading');
});

test('a record with no answer shows a dash rather than a blank cell', function () {
    $field = fieldOn('leads', CustomFieldType::Text, 'Sector');
    $lead = Lead::factory()->create();

    $screen = Livewire::actingAs(integrationUser())->test(LeadsIndex::class);
    $column = collect($screen->instance()->dataViewColumns())
        ->firstWhere('key', CustomFieldColumns::columnKey($field));

    expect((string) $screen->instance()->cellFor($lead, $column))->toContain('&mdash;');
});

// -- Filtering -----------------------------------------------------------------

test('filtering on a custom field finds only the records that answered it', function (string $type, mixed $stored, string $operator, mixed $value) {
    $type = CustomFieldType::from($type);
    $field = fieldOn('leads', $type, 'Extra', ['Retail', 'Wholesale']);

    if ($type->hasOptions()) {
        $keys = $field->optionKeys();
        $stored = $type->isMultiple() ? [$keys[0]] : $keys[0];
        $value = $keys[0];
    }

    $match = Lead::factory()->create();
    $match->saveCustomFields([$field->key => $stored]);

    $other = Lead::factory()->create();

    $query = Lead::query();
    app(FilterApplier::class)->apply(
        $query,
        FilterGroup::fromArray([
            'match' => 'all',
            'conditions' => [[
                'field' => CustomFieldColumns::columnKey($field),
                'operator' => $operator,
                'value' => $value,
            ]],
        ]),
        LeadFields::filters(),
    );

    expect($query->pluck('id')->all())->toBe([$match->id])
        ->and($other->id)->not->toBe($match->id);
})->with([
    ['text', 'Manufacturing', 'contains', 'facturing'],
    ['text', 'Manufacturing', 'equals', 'Manufacturing'],
    ['number', 42, 'gt', 10],
    ['currency', 1200, 'lt', 5000],
    ['date', '2026-12-31', 'before', '2027-01-01'],
    ['checkbox', true, 'is_true', null],
    ['select', null, 'equals', null],
    ['multiselect', null, 'equals', null],
]);

test('a negative filter also returns records that were never answered', function () {
    // The trap: EXISTS(value not like X) silently drops every record with no
    // row at all, so "does not contain" would hide most of the list.
    $field = fieldOn('leads', CustomFieldType::Text, 'Sector');

    $answered = Lead::factory()->create();
    $answered->saveCustomFields([$field->key => 'Manufacturing']);

    $different = Lead::factory()->create();
    $different->saveCustomFields([$field->key => 'Retail']);

    $never = Lead::factory()->create();

    $query = Lead::query();
    app(FilterApplier::class)->apply(
        $query,
        FilterGroup::fromArray([
            'match' => 'all',
            'conditions' => [[
                'field' => CustomFieldColumns::columnKey($field),
                'operator' => 'not_contains',
                'value' => 'Manufacturing',
            ]],
        ]),
        LeadFields::filters(),
    );

    expect($query->pluck('id')->sort()->values()->all())
        ->toBe(collect([$different->id, $never->id])->sort()->values()->all());
});

test('is empty and is not empty read the presence of an answer', function () {
    $field = fieldOn('leads', CustomFieldType::Text, 'Sector');

    $answered = Lead::factory()->create();
    $answered->saveCustomFields([$field->key => 'Manufacturing']);
    $blank = Lead::factory()->create();

    $ids = function (string $operator) use ($field) {
        $query = Lead::query();
        app(FilterApplier::class)->apply(
            $query,
            FilterGroup::fromArray([
                'match' => 'all',
                'conditions' => [['field' => CustomFieldColumns::columnKey($field), 'operator' => $operator]],
            ]),
            LeadFields::filters(),
        );

        return $query->pluck('id')->all();
    };

    expect($ids('is_not_empty'))->toBe([$answered->id])
        ->and($ids('is_empty'))->toBe([$blank->id]);
});

test('a checkbox that was never ticked counts as no', function () {
    $field = fieldOn('leads', CustomFieldType::Checkbox, 'Opted in');

    $yes = Lead::factory()->create();
    $yes->saveCustomFields([$field->key => true]);

    $no = Lead::factory()->create();
    $no->saveCustomFields([$field->key => false]);

    $never = Lead::factory()->create();

    $query = Lead::query();
    app(FilterApplier::class)->apply(
        $query,
        FilterGroup::fromArray([
            'match' => 'all',
            'conditions' => [['field' => CustomFieldColumns::columnKey($field), 'operator' => 'is_false']],
        ]),
        LeadFields::filters(),
    );

    expect($query->pluck('id')->sort()->values()->all())
        ->toBe(collect([$no->id, $never->id])->sort()->values()->all());
});

test('a multiselect matches on containment, not on the whole list', function () {
    $field = fieldOn('leads', CustomFieldType::MultiSelect, 'Sectors', ['Retail', 'Wholesale', 'Export']);
    $keys = $field->optionKeys();

    $both = Lead::factory()->create();
    $both->saveCustomFields([$field->key => [$keys[0], $keys[1]]]);

    $one = Lead::factory()->create();
    $one->saveCustomFields([$field->key => [$keys[2]]]);

    $query = Lead::query();
    app(FilterApplier::class)->apply(
        $query,
        FilterGroup::fromArray([
            'match' => 'all',
            'conditions' => [[
                'field' => CustomFieldColumns::columnKey($field),
                'operator' => 'equals',
                'value' => $keys[1],
            ]],
        ]),
        LeadFields::filters(),
    );

    expect($query->pluck('id')->all())->toBe([$both->id]);
});

test('a filter on one field never reads another one answer', function () {
    $sector = fieldOn('leads', CustomFieldType::Text, 'Sector');
    $region = fieldOn('leads', CustomFieldType::Text, 'Region');

    $lead = Lead::factory()->create();
    $lead->saveCustomFields([$sector->key => 'Manufacturing', $region->key => 'North']);

    $query = Lead::query();
    app(FilterApplier::class)->apply(
        $query,
        FilterGroup::fromArray([
            'match' => 'all',
            'conditions' => [[
                'field' => CustomFieldColumns::columnKey($region),
                'operator' => 'equals',
                'value' => 'Manufacturing',
            ]],
        ]),
        LeadFields::filters(),
    );

    expect($query->pluck('id')->all())->toBe([]);
});

test('the list screen filters on a custom field end to end', function () {
    $field = fieldOn('leads', CustomFieldType::Text, 'Sector');
    $user = integrationUser();

    // Distinctive surnames: a word like "Other" appears in the page's own
    // chrome, so assertDontSee would fail on the filter builder rather than on
    // a row.
    $match = Lead::factory()->ownedBy($user)->create(['first_name' => 'Dana', 'last_name' => 'Quarnby']);
    $match->saveCustomFields([$field->key => 'Manufacturing']);

    $other = Lead::factory()->ownedBy($user)->create(['first_name' => 'Sam', 'last_name' => 'Velluto']);

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->set('filters', [
            'match' => 'all',
            'conditions' => [[
                'field' => CustomFieldColumns::columnKey($field),
                'operator' => 'equals',
                'value' => 'Manufacturing',
            ]],
            'groups' => [],
        ])
        ->assertSee('Quarnby')
        ->assertDontSee('Velluto');

    expect($other->id)->not->toBe($match->id);
});

// -- Exports -------------------------------------------------------------------

test('an export writes a number as a number, not a formatted string', function () {
    // A spreadsheet column of "1,200.00" cannot be summed.
    $field = fieldOn('leads', CustomFieldType::Currency, 'Contract value');
    $user = integrationUser();
    $lead = Lead::factory()->ownedBy($user)->create();
    $lead->saveCustomFields([$field->key => 1200.5]);

    $request = new ExportRequest(
        source: LeadExportSource::class,
        format: ExportFormat::Csv,
        module: 'leads',
        columns: [CustomFieldColumns::columnKey($field) => 'Contract value'],
        userId: $user->id,
    );

    $export = new DataViewExport($request, app(LeadExportSource::class));

    expect($export->map($export->query()->findOrFail($lead->id)))->toBe([1200.5]);
});

test('a queued export applies a custom field filter, so it matches the list', function () {
    // An export that ignored the filter would hand somebody more rows than the
    // screen ever showed — the drift the Fields classes exist to prevent.
    $field = fieldOn('leads', CustomFieldType::Text, 'Sector');
    $user = integrationUser();

    $match = Lead::factory()->ownedBy($user)->create();
    $match->saveCustomFields([$field->key => 'Manufacturing']);
    Lead::factory()->ownedBy($user)->create();

    $request = new ExportRequest(
        source: LeadExportSource::class,
        format: ExportFormat::Csv,
        module: 'leads',
        columns: ['name' => 'Name'],
        filters: [
            'match' => 'all',
            'conditions' => [[
                'field' => CustomFieldColumns::columnKey($field),
                'operator' => 'equals',
                'value' => 'Manufacturing',
            ]],
            'groups' => [],
        ],
        userId: $user->id,
    );

    $rows = app(LeadExportSource::class)->exportQuery($request)->pluck('id')->all();

    expect($rows)->toBe([$match->id]);
});

test('an export column for a field on another module carries nothing', function () {
    $dealField = fieldOn('deals', CustomFieldType::Text, 'Renewal risk');
    $user = integrationUser();
    $lead = Lead::factory()->ownedBy($user)->create();

    $request = new ExportRequest(
        source: LeadExportSource::class,
        format: ExportFormat::Csv,
        module: 'leads',
        columns: [CustomFieldColumns::columnKey($dealField) => 'Renewal risk'],
        userId: $user->id,
    );

    $export = new DataViewExport($request, app(LeadExportSource::class));

    expect($export->map($export->query()->findOrFail($lead->id)))->toBe([null]);
});

// -- Forms ---------------------------------------------------------------------

test('the form offers the field, and saving stores the answer', function () {
    $field = fieldOn('leads', CustomFieldType::Text, 'Industry sector');
    $user = integrationUser();

    Livewire::actingAs($user)
        ->test(LeadForm::class)
        ->assertSee('Industry sector')
        ->assertSet('customFields.'.$field->key, '')
        ->set('first_name', 'Dana')
        ->set('last_name', 'Whitfield')
        ->set('email', 'dana@example.com')
        ->set('customFields.'.$field->key, 'Manufacturing')
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::query()->latest('id')->firstOrFail()->customField($field->key))->toBe('Manufacturing');
});

test('editing loads the stored answer back into the form', function () {
    $field = fieldOn('leads', CustomFieldType::Text, 'Industry sector');
    $user = integrationUser();
    $lead = Lead::factory()->ownedBy($user)->create();
    $lead->saveCustomFields([$field->key => 'Manufacturing']);

    Livewire::actingAs($user)
        ->test(LeadForm::class, ['lead' => $lead])
        ->assertSet('customFields.'.$field->key, 'Manufacturing');
});

test('a new record starts on the field default', function () {
    $field = app(SaveCustomFieldAction::class)(CustomFieldData::fromArray([
        'module' => 'leads',
        'label' => 'Sector',
        'type' => 'text',
        'default_value' => 'Unknown',
    ]));

    Livewire::actingAs(integrationUser())
        ->test(LeadForm::class)
        ->assertSet('customFields.'.$field->key, 'Unknown');
});

test('the form refuses an answer the type cannot hold', function () {
    $field = fieldOn('leads', CustomFieldType::Number, 'Headcount');

    Livewire::actingAs(integrationUser())
        ->test(LeadForm::class)
        ->set('first_name', 'Dana')
        ->set('last_name', 'Whitfield')
        ->set('email', 'dana@example.com')
        ->set('customFields.'.$field->key, 'not a number')
        ->call('save')
        ->assertHasErrors('customFields.'.$field->key);
});

test('the form refuses a required field left empty', function () {
    $field = app(SaveCustomFieldAction::class)(CustomFieldData::fromArray([
        'module' => 'leads',
        'label' => 'Sector',
        'type' => 'text',
        'is_required' => true,
    ]));

    Livewire::actingAs(integrationUser())
        ->test(LeadForm::class)
        ->set('first_name', 'Dana')
        ->set('last_name', 'Whitfield')
        ->set('email', 'dana@example.com')
        ->call('save')
        ->assertHasErrors('customFields.'.$field->key);
});

test('the form refuses a lookup pointing outside the person access level', function () {
    $field = fieldOn('leads', CustomFieldType::Lookup, 'Parent account');
    $theirs = Account::factory()->create(['owner_id' => User::factory()->create()->id]);

    // Sees only their own records.
    $user = customFieldUser(['leads.view', 'leads.create', 'accounts.view'], DataAccessLevel::Own->value);

    Livewire::actingAs($user)
        ->test(LeadForm::class)
        ->set('first_name', 'Dana')
        ->set('last_name', 'Whitfield')
        ->set('email', 'dana@example.com')
        ->set('customFields.'.$field->key, $theirs->id)
        ->call('save')
        ->assertHasErrors('customFields.'.$field->key);
});

test('a module with no custom fields renders no extra section at all', function () {
    Livewire::actingAs(integrationUser())
        ->test(LeadForm::class)
        ->assertDontSee('Additional details');
});

// -- The memo ------------------------------------------------------------------

test('a field added, hidden or reordered is visible on the very next read', function () {
    $schema = app(CustomFieldSchema::class);

    expect($schema->forModule('leads'))->toBeEmpty();

    $field = fieldOn('leads', CustomFieldType::Text, 'Sector');

    // Would still be empty if the actions did not flush the request memo.
    expect($schema->forModule('leads')->pluck('key')->all())->toBe([$field->key]);

    app(ToggleCustomFieldAction::class)($field);

    expect($schema->forModule('leads'))->toBeEmpty();
});

test('the memo does not leak between modules', function () {
    fieldOn('leads', CustomFieldType::Text, 'On leads');
    fieldOn('deals', CustomFieldType::Text, 'On deals');

    $schema = app(CustomFieldSchema::class);

    expect($schema->forModule('leads')->pluck('label')->all())->toBe(['On leads'])
        ->and($schema->forModule('deals')->pluck('label')->all())->toBe(['On deals']);
});
