<?php

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\PermissionResolver;
use App\Domain\Accounts\Models\Account;
use App\Domain\Activities\Models\Activity;
use App\Domain\Contacts\Models\Contact;
use App\Domain\CustomFields\Actions\DeleteCustomFieldAction;
use App\Domain\CustomFields\Actions\ReorderCustomFieldsAction;
use App\Domain\CustomFields\Actions\SaveCustomFieldAction;
use App\Domain\CustomFields\Concerns\HasCustomFields;
use App\Domain\CustomFields\CustomFieldRegistry;
use App\Domain\CustomFields\CustomFieldValidator;
use App\Domain\CustomFields\DTOs\CustomFieldData;
use App\Domain\CustomFields\Enums\CustomFieldType;
use App\Domain\CustomFields\Models\CustomField;
use App\Domain\CustomFields\Models\CustomFieldValue;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Models\Lead;
use App\Domain\Products\Models\Product;
use App\Domain\Sales\Models\Quote;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

/**
 * @param  array<int, string>  $permissions
 */
function customFieldUser(array $permissions = ['custom-fields.view', 'custom-fields.manage'], string $level = DataAccessLevel::All->value): User
{
    $role = Role::query()->create([
        'name' => 'Custom fields '.uniqid(),
        'guard_name' => Guard::getDefaultName(Role::class),
        'data_access_level' => $level,
    ]);

    $role->syncPermissions(PermissionResolver::models($permissions));

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * A field of a given type on leads, saved through the action so the fixture is
 * something the application could have produced.
 *
 * @param  array<int, string>  $options
 */
function leadField(CustomFieldType $type, string $label = 'Extra', bool $required = false, array $options = ['One', 'Two'], ?string $lookupModule = 'accounts'): CustomField
{
    return app(SaveCustomFieldAction::class)(CustomFieldData::fromArray([
        'module' => 'leads',
        'label' => $label,
        'type' => $type->value,
        'is_required' => $required,
        'options' => array_map(fn (string $o) => ['label' => $o], $options),
        'lookup_module' => $lookupModule,
    ]));
}

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow('2026-10-15 09:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

// -- Schema --------------------------------------------------------------------

test('a value has a real column per type, not one serialised blob', function () {
    // The single-column EAV shape cannot be indexed usefully and sorts numbers
    // as text. 4.2 puts custom fields in the filter builder, so each type needs
    // a column of its own.
    foreach (CustomFieldType::cases() as $type) {
        expect(Schema::hasColumn('custom_field_values', $type->column()))
            ->toBeTrue("no column for {$type->value}");
    }
});

test('every value column is one the model knows how to clear', function () {
    foreach (CustomFieldType::cases() as $type) {
        expect(CustomFieldValue::VALUE_COLUMNS)->toContain($type->column());
    }
});

// -- The registry --------------------------------------------------------------

test('every module in the registry is a model that actually carries custom fields', function (string $module) {
    $class = CustomFieldRegistry::modelClass($module);

    expect($class)->not->toBeNull()
        ->and(in_array(HasCustomFields::class, class_uses_recursive($class), true))->toBeTrue();
    // builtInKeys, not keys: a dataset closure is resolved before the test
    // database exists, and keys() now reads the generated modules.
})->with(fn () => CustomFieldRegistry::builtInKeys());

test('every module in the registry can be queried for lookup choices', function (string $module) {
    // A module in subjects() with no branch in visibleQuery() would offer a
    // lookup field no choices at all.
    expect(CustomFieldRegistry::visibleQuery($module, customFieldUser()))->not->toBeNull();
    // builtInKeys, not keys: a dataset closure is resolved before the test
    // database exists, and keys() now reads the generated modules.
})->with(fn () => CustomFieldRegistry::builtInKeys());

test('a module the registry does not list resolves to nothing', function () {
    expect(CustomFieldRegistry::has('invoices'))->toBeFalse()
        ->and(CustomFieldRegistry::modelClass('invoices'))->toBeNull()
        ->and(CustomFieldRegistry::visibleQuery('invoices', customFieldUser()))->toBeNull();
});

test('a model using the trait but missing from the registry says so loudly', function () {
    // Silently collecting no fields would look identical to "none configured".
    $orphan = new class extends Model
    {
        use HasCustomFields;
    };

    expect(fn () => $orphan->customFieldModule())
        ->toThrow(RuntimeException::class, 'CustomFieldRegistry');
});

// -- Keys ----------------------------------------------------------------------

test('a key is derived from the label, never taken from the browser', function () {
    $field = app(SaveCustomFieldAction::class)(CustomFieldData::fromArray([
        'module' => 'leads',
        'label' => 'Industry Sector!',
        'type' => 'text',
        // A payload trying to name the key.
        'key' => 'something_else',
    ]));

    expect($field->key)->toBe('industry_sector');
});

test('a key never changes once set, however the label is edited', function () {
    $field = leadField(CustomFieldType::Text, 'Industry sector');
    $original = $field->key;

    app(SaveCustomFieldAction::class)(CustomFieldData::fromArray([
        'module' => 'leads',
        'label' => 'Sector of industry',
        'type' => 'text',
    ]), $field);

    expect($field->fresh()->key)->toBe($original)
        ->and($field->fresh()->label)->toBe('Sector of industry');
});

test('two fields with the same label get different keys', function () {
    leadField(CustomFieldType::Text, 'Sector');
    $second = leadField(CustomFieldType::Text, 'Sector');

    expect($second->key)->toBe('sector_2');
});

test('a field cannot be moved between modules', function () {
    // Its values hang off records of the old module's type; moving the
    // definition would strand every one of them.
    $field = leadField(CustomFieldType::Text, 'Sector');

    app(SaveCustomFieldAction::class)(CustomFieldData::fromArray([
        'module' => 'deals',
        'label' => 'Sector',
        'type' => 'text',
    ]), $field);

    expect($field->fresh()->module)->toBe('leads');
});

test('a module the registry does not list is refused outright', function () {
    expect(fn () => app(SaveCustomFieldAction::class)(CustomFieldData::fromArray([
        'module' => 'invoices',
        'label' => 'Anything',
        'type' => 'text',
    ])))->toThrow(RuntimeException::class);
});

test('an option keeps its key when its label is edited', function () {
    $field = leadField(CustomFieldType::Select, 'Sector', options: ['Manufacturing', 'Retail']);
    $keys = $field->optionKeys();

    app(SaveCustomFieldAction::class)(CustomFieldData::fromArray([
        'module' => 'leads',
        'label' => 'Sector',
        'type' => 'select',
        'options' => [
            ['key' => $keys[0], 'label' => 'Heavy manufacturing'],
            ['key' => $keys[1], 'label' => 'Retail'],
        ],
    ]), $field);

    // Renaming a choice must not orphan every record that chose it.
    expect($field->fresh()->optionKeys())->toBe($keys)
        ->and($field->fresh()->optionMap()[$keys[0]])->toBe('Heavy manufacturing');
});

test('an option key the field does not already hold is treated as a new choice', function () {
    $normalised = CustomFieldData::normaliseOptions([
        ['key' => 'not; a key', 'label' => 'Retail'],
        ['key' => '', 'label' => 'Wholesale'],
    ]);

    expect($normalised[0]['key'])->toBe('retail')
        ->and($normalised[1]['key'])->toBe('wholesale');
});

test('an option with no label is dropped rather than stored blank', function () {
    expect(CustomFieldData::normaliseOptions([['label' => ''], ['label' => 'Retail']]))->toHaveCount(1);
});

// -- Values save and load, per type --------------------------------------------

test('a value saves and loads for every type', function (string $type, mixed $submitted, mixed $expected) {
    $type = CustomFieldType::from($type);
    $lead = Lead::factory()->create();

    $field = leadField($type, 'Extra '.$type->value, options: ['One', 'Two']);

    // A lookup points at a real record, so the id has to be one.
    if ($type->isLookup()) {
        $submitted = Account::factory()->create()->id;
        $expected = $submitted;
    }

    if ($type->hasOptions()) {
        $keys = $field->optionKeys();
        $submitted = $type->isMultiple() ? [$keys[0], $keys[1]] : $keys[0];
        $expected = $submitted;
    }

    $lead->saveCustomFields([$field->key => $submitted]);

    expect($lead->fresh()->customField($field->key))->toBe($expected);
})->with([
    ['text', 'Manufacturing', 'Manufacturing'],
    ['number', '42.5', 42.5],
    ['currency', '1200', 1200.0],
    ['date', '2026-12-31', '2026-12-31'],
    ['checkbox', true, true],
    ['select', null, null],
    ['multiselect', null, null],
    ['lookup', null, null],
]);

test('each type is stored in its own column and no other', function (string $type) {
    $type = CustomFieldType::from($type);
    $lead = Lead::factory()->create();
    $field = leadField($type, 'Extra '.$type->value);

    $value = match (true) {
        $type->isMultiple() => $field->optionKeys(),
        $type->hasOptions() => $field->optionKeys()[0],
        $type->isLookup() => Account::factory()->create()->id,
        $type === CustomFieldType::Date => '2026-12-31',
        $type === CustomFieldType::Checkbox => true,
        $type === CustomFieldType::Number, $type === CustomFieldType::Currency => 12.5,
        default => 'Something',
    };

    $lead->saveCustomFields([$field->key => $value]);

    $row = CustomFieldValue::query()->where('custom_field_id', $field->id)->firstOrFail();

    expect($row->getAttribute($type->column()))->not->toBeNull();

    foreach (CustomFieldValue::VALUE_COLUMNS as $column) {
        if ($column !== $type->column()) {
            expect($row->getAttribute($column))->toBeNull("{$column} should be empty for {$type->value}");
        }
    }
})->with(fn () => array_map(fn (CustomFieldType $t) => $t->value, CustomFieldType::cases()));

test('a currency value keeps two decimal places exactly', function () {
    $lead = Lead::factory()->create();
    $field = leadField(CustomFieldType::Currency, 'Contract value');

    $lead->saveCustomFields([$field->key => '1234.56']);

    expect($lead->fresh()->customField($field->key))->toBe(1234.56);
});

test('a checkbox stores false as an answer rather than as nothing', function () {
    $lead = Lead::factory()->create();
    $field = leadField(CustomFieldType::Checkbox, 'Opted in');

    $lead->saveCustomFields([$field->key => false]);

    expect($lead->fresh()->customField($field->key))->toBeFalse()
        ->and(CustomFieldValue::query()->where('custom_field_id', $field->id)->exists())->toBeTrue();
});

test('clearing a value removes the row rather than storing a row of nulls', function () {
    $lead = Lead::factory()->create();
    $field = leadField(CustomFieldType::Text, 'Sector');

    $lead->saveCustomFields([$field->key => 'Manufacturing']);
    expect(CustomFieldValue::query()->count())->toBe(1);

    $lead->saveCustomFields([$field->key => '']);

    // Empty rows would make every "is not empty" filter wrong.
    expect(CustomFieldValue::query()->count())->toBe(0)
        ->and($lead->fresh()->customField($field->key))->toBeNull();
});

test('saving the same field twice updates one row rather than adding another', function () {
    $lead = Lead::factory()->create();
    $field = leadField(CustomFieldType::Text, 'Sector');

    $lead->saveCustomFields([$field->key => 'Manufacturing']);
    $first = CustomFieldValue::query()->firstOrFail();

    $lead->saveCustomFields([$field->key => 'Retail']);

    expect(CustomFieldValue::query()->count())->toBe(1)
        ->and(CustomFieldValue::query()->firstOrFail()->id)->toBe($first->id)
        ->and($lead->fresh()->customField($field->key))->toBe('Retail');
});

test('changing a field type clears the column the old type used', function () {
    $lead = Lead::factory()->create();
    $field = leadField(CustomFieldType::Text, 'Sector');

    $lead->saveCustomFields([$field->key => 'Manufacturing']);

    app(SaveCustomFieldAction::class)(CustomFieldData::fromArray([
        'module' => 'leads',
        'label' => 'Sector',
        'type' => 'number',
    ]), $field);

    $lead->saveCustomFields([$field->fresh()->key => 99]);

    $row = CustomFieldValue::query()->firstOrFail();

    // A stale value_string would still match a text filter, and the record
    // would turn up in a list that does not describe it.
    expect($row->value_string)->toBeNull()
        ->and((float) $row->value_number)->toBe(99.0);
});

test('a key that names no field on this module is ignored, not stored', function () {
    $lead = Lead::factory()->create();
    leadField(CustomFieldType::Text, 'Sector');

    $written = $lead->saveCustomFields(['not_a_field' => 'anything']);

    expect($written)->toBe(0)
        ->and(CustomFieldValue::query()->count())->toBe(0);
});

test('a field defined on another module cannot be answered from this one', function () {
    $lead = Lead::factory()->create();

    $dealField = app(SaveCustomFieldAction::class)(CustomFieldData::fromArray([
        'module' => 'deals',
        'label' => 'Renewal risk',
        'type' => 'text',
    ]));

    expect($lead->saveCustomFields([$dealField->key => 'High']))->toBe(0)
        ->and(CustomFieldValue::query()->count())->toBe(0);
});

test('a multiselect drops an option the definition never offered', function () {
    $lead = Lead::factory()->create();
    $field = leadField(CustomFieldType::MultiSelect, 'Sectors', options: ['Retail', 'Wholesale']);
    $keys = $field->optionKeys();

    $lead->saveCustomFields([$field->key => [$keys[0], 'invented_option']]);

    expect($lead->fresh()->customField($field->key))->toBe([$keys[0]]);
});

test('answers are kept when a field is hidden, and lost when it is removed', function () {
    $lead = Lead::factory()->create();
    $field = leadField(CustomFieldType::Text, 'Sector');
    $lead->saveCustomFields([$field->key => 'Manufacturing']);

    $field->forceFill(['is_active' => false])->save();

    // Hidden from the form, but the answer is still on the record.
    expect($lead->fresh()->customFields()->pluck('key')->all())->not->toContain($field->key)
        ->and($lead->fresh()->customFieldValuesByKey())->toHaveKey($field->key);

    app(DeleteCustomFieldAction::class)($field);

    expect(CustomFieldValue::query()->count())->toBe(0);
});

test('every module that carries custom fields can actually store one', function (string $module) {
    $record = match ($module) {
        'leads' => Lead::factory()->create(),
        'contacts' => Contact::factory()->create(),
        'accounts' => Account::factory()->create(),
        'deals' => Deal::factory()->create(),
        'products' => Product::factory()->create(),
        'quotes' => Quote::factory()->create(),
        'activities' => Activity::factory()->create(),
    };

    $field = app(SaveCustomFieldAction::class)(CustomFieldData::fromArray([
        'module' => $module,
        'label' => 'Extra note',
        'type' => 'text',
    ]));

    $record->saveCustomFields([$field->key => 'Stored']);

    expect($record->fresh()->customField($field->key))->toBe('Stored');
    // builtInKeys, not keys: a dataset closure is resolved before the test
    // database exists, and keys() now reads the generated modules.
})->with(fn () => CustomFieldRegistry::builtInKeys());

test('two records answer the same field independently', function () {
    $field = leadField(CustomFieldType::Text, 'Sector');
    $one = Lead::factory()->create();
    $two = Lead::factory()->create();

    $one->saveCustomFields([$field->key => 'Manufacturing']);
    $two->saveCustomFields([$field->key => 'Retail']);

    expect($one->fresh()->customField($field->key))->toBe('Manufacturing')
        ->and($two->fresh()->customField($field->key))->toBe('Retail');
});

test('records of different modules sharing an id do not share answers', function () {
    // A polymorphic table is addressed by type and id together.
    $lead = Lead::factory()->create();
    $account = Account::factory()->create(['id' => $lead->id]);

    $leadField = leadField(CustomFieldType::Text, 'Sector');
    $accountField = app(SaveCustomFieldAction::class)(CustomFieldData::fromArray([
        'module' => 'accounts',
        'label' => 'Sector',
        'type' => 'text',
    ]));

    $lead->saveCustomFields([$leadField->key => 'From the lead']);
    $account->saveCustomFields([$accountField->key => 'From the account']);

    expect($lead->fresh()->customField($leadField->key))->toBe('From the lead')
        ->and($account->fresh()->customField($accountField->key))->toBe('From the account');
});

// -- Validation, per type ------------------------------------------------------

test('validation rejects what the type cannot hold', function (string $type, mixed $value) {
    $field = leadField(CustomFieldType::from($type), 'Extra');

    $validator = app(CustomFieldValidator::class)->make([$field], [$field->key => $value]);

    expect($validator->fails())->toBeTrue();
})->with([
    ['number', 'not a number'],
    ['currency', 'free'],
    // Money is never negative: a negative contract value is a slip that would
    // make every report that sums it wrong.
    ['currency', -5],
    ['date', 'the thirty-first'],
    ['multiselect', 'not an array'],
    ['lookup', 'abc'],
]);

test('validation accepts what the type can hold', function (string $type, mixed $value) {
    $field = leadField(CustomFieldType::from($type), 'Extra');

    $validator = app(CustomFieldValidator::class)->make([$field], [$field->key => $value]);

    expect($validator->fails())->toBeFalse(json_encode($validator->errors()->all()));
})->with([
    ['text', 'Manufacturing'],
    ['number', '42.5'],
    ['number', -3],
    ['currency', '1200.50'],
    ['date', '2026-12-31'],
    ['checkbox', true],
    ['checkbox', false],
    ['lookup', 7],
]);

test('a select refuses a value its dropdown never offered', function () {
    $field = leadField(CustomFieldType::Select, 'Sector', options: ['Retail', 'Wholesale']);

    expect(app(CustomFieldValidator::class)->make([$field], [$field->key => 'invented'])->fails())->toBeTrue()
        ->and(app(CustomFieldValidator::class)->make([$field], [$field->key => 'retail'])->fails())->toBeFalse();
});

test('a multiselect refuses an entry its dropdown never offered', function () {
    $field = leadField(CustomFieldType::MultiSelect, 'Sectors', options: ['Retail', 'Wholesale']);

    expect(app(CustomFieldValidator::class)->make([$field], [$field->key => ['retail', 'invented']])->fails())->toBeTrue()
        ->and(app(CustomFieldValidator::class)->make([$field], [$field->key => ['retail']])->fails())->toBeFalse();
});

test('a text value longer than the column is refused on the form, not by the database', function () {
    $field = leadField(CustomFieldType::Text, 'Sector');

    expect(app(CustomFieldValidator::class)->make([$field], [$field->key => str_repeat('a', 256)])->fails())->toBeTrue();
});

test('a required field is refused when left empty, per type', function (string $type) {
    $type = CustomFieldType::from($type);
    $field = leadField($type, 'Extra', required: true);

    $empty = $type->isMultiple() ? [] : ($type === CustomFieldType::Checkbox ? false : '');

    expect(app(CustomFieldValidator::class)->make([$field], [$field->key => $empty])->fails())->toBeTrue();
})->with(fn () => array_map(fn (CustomFieldType $t) => $t->value, CustomFieldType::cases()));

test('an optional field is happy with nothing at all, per type', function (string $type) {
    $type = CustomFieldType::from($type);
    $field = leadField($type, 'Extra');

    $empty = $type->isMultiple() ? [] : ($type === CustomFieldType::Checkbox ? false : null);

    $validator = app(CustomFieldValidator::class)->make([$field], [$field->key => $empty]);

    expect($validator->fails())->toBeFalse(json_encode($validator->errors()->all()));
})->with(fn () => array_map(fn (CustomFieldType $t) => $t->value, CustomFieldType::cases()));

test('a required checkbox must actually be ticked', function () {
    $field = leadField(CustomFieldType::Checkbox, 'Accepted terms', required: true);

    // A plain "required" rule passes on false, which is not what "required"
    // means for a tickbox.
    expect(app(CustomFieldValidator::class)->make([$field], [$field->key => false])->fails())->toBeTrue()
        ->and(app(CustomFieldValidator::class)->make([$field], [$field->key => true])->fails())->toBeFalse();
});

test('the error names the field the way the form labels it', function () {
    $field = leadField(CustomFieldType::Text, 'Industry sector', required: true);

    $validator = app(CustomFieldValidator::class)->make([$field], [$field->key => '']);

    expect($validator->errors()->first())->toContain('industry sector')
        ->and($validator->errors()->first())->not->toContain('customFields.');
});

// -- Lookups -------------------------------------------------------------------

test('a lookup accepts a record the person can see', function () {
    $viewer = customFieldUser();
    $account = Account::factory()->create();
    $field = leadField(CustomFieldType::Lookup, 'Parent account', lookupModule: 'accounts');

    expect(app(CustomFieldValidator::class)->lookupErrors([$field], [$field->key => $account->id], $viewer))->toBe([]);
});

test('a lookup refuses a record outside the person access level', function () {
    // An id that exists is not an id this person may use — and accepting any
    // integer would confirm the existence of records they cannot see.
    $viewer = customFieldUser(['custom-fields.view'], DataAccessLevel::Own->value);
    $theirs = Account::factory()->create(['owner_id' => User::factory()->create()->id]);
    $field = leadField(CustomFieldType::Lookup, 'Parent account', lookupModule: 'accounts');

    $errors = app(CustomFieldValidator::class)->lookupErrors([$field], [$field->key => $theirs->id], $viewer);

    expect($errors)->toHaveKey($field->key);
});

test('a lookup refuses an id that names nothing', function () {
    $field = leadField(CustomFieldType::Lookup, 'Parent account', lookupModule: 'accounts');

    expect(app(CustomFieldValidator::class)->lookupErrors([$field], [$field->key => 999999], customFieldUser()))
        ->toHaveKey($field->key);
});

test('a lookup pointing at a module that has gone says so rather than passing', function () {
    $field = leadField(CustomFieldType::Lookup, 'Parent account', lookupModule: 'accounts');
    $field->forceFill(['lookup_module' => 'invoices'])->save();

    expect(app(CustomFieldValidator::class)->lookupErrors([$field->fresh()], [$field->key => 1], customFieldUser()))
        ->toHaveKey($field->key);
});

// -- Ordering ------------------------------------------------------------------

test('a new field is appended rather than inserted', function () {
    $first = leadField(CustomFieldType::Text, 'One');
    $second = leadField(CustomFieldType::Text, 'Two');

    expect($second->position)->toBeGreaterThan($first->position);
});

test('reordering takes ids from the browser, so it verifies them', function () {
    $one = leadField(CustomFieldType::Text, 'One');
    $two = leadField(CustomFieldType::Text, 'Two');
    $three = leadField(CustomFieldType::Text, 'Three');

    // An unknown id, and one field the browser never mentioned.
    app(ReorderCustomFieldsAction::class)('leads', [$three->id, 999999, $one->id]);

    $order = CustomField::query()->forModule('leads')->ordered()->pluck('id')->all();

    expect($order)->toBe([$three->id, $one->id, $two->id]);
});

test('reordering cannot pull a field out of another module', function () {
    $lead = leadField(CustomFieldType::Text, 'One');
    $deal = app(SaveCustomFieldAction::class)(CustomFieldData::fromArray([
        'module' => 'deals',
        'label' => 'Renewal risk',
        'type' => 'text',
    ]));
    $dealPosition = $deal->position;

    app(ReorderCustomFieldsAction::class)('leads', [$deal->id, $lead->id]);

    expect($deal->fresh()->position)->toBe($dealPosition)
        ->and($deal->fresh()->module)->toBe('deals');
});

// -- Permissions and audit -----------------------------------------------------

test('every custom field permission is declared in the catalogue', function (string $permission) {
    expect(PermissionCatalogue::has($permission))->toBeTrue();
})->with(['custom-fields.view', 'custom-fields.manage']);

test('a field records audit entries under its own label', function () {
    $field = leadField(CustomFieldType::Text, 'Sector');

    expect(CustomField::activitySubjectLabel())->toBe('Custom field')
        ->and($field->activities()->count())->toBeGreaterThan(0);
});

test('the audit allowlist never grows implicitly', function () {
    $field = leadField(CustomFieldType::Text, 'Sector');
    $logged = (new ReflectionClass(CustomField::class))->getMethod('activityAttributes');
    $logged->setAccessible(true);

    /** @var array<int, string> $attributes */
    $attributes = $logged->invoke($field);

    expect($attributes)->toContain('key')
        ->and($attributes)->toContain('type')
        // Help text and defaults are content, not the shape of the field.
        ->and($attributes)->not->toContain('help')
        ->and($attributes)->not->toContain('default_value');
});
