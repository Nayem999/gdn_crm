<?php

use App\Domain\Shared\Enums\FilterFieldType;
use App\Domain\Shared\Enums\FilterOperator;
use App\Domain\Shared\Enums\FilterValueMode;
use App\Domain\Shared\Filters\FilterApplier;
use App\Domain\Shared\Filters\FilterCondition;
use App\Domain\Shared\Filters\FilterField;
use App\Domain\Shared\Filters\FilterGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * A stand-in record with one column per filterable type.
 */
function filterableModel(): Model
{
    if (! Schema::hasTable('filterable_records')) {
        Schema::create('filterable_records', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->integer('score')->nullable();
            $table->date('closes_on')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_active')->nullable();
            $table->timestamps();
        });
    }

    return new class extends Model
    {
        protected $table = 'filterable_records';

        protected $fillable = ['name', 'score', 'closes_on', 'status', 'is_active'];

        protected $casts = ['closes_on' => 'date', 'is_active' => 'boolean'];
    };
}

/**
 * @return array<string, FilterField>
 */
function filterFields(): array
{
    return collect([
        FilterField::text('name', 'Name'),
        FilterField::number('score', 'Score'),
        FilterField::date('closes_on', 'Closes on'),
        FilterField::select('status', 'Status', ['open' => 'Open', 'won' => 'Won', 'lost' => 'Lost']),
        FilterField::boolean('is_active', 'Active'),
    ])->keyBy('key')->all();
}

/**
 * Apply one condition and return the matching names.
 *
 * @return array<int, string>
 */
function matchNames(FilterCondition $condition): array
{
    $model = filterableModel();

    $query = $model->newQuery();

    app(FilterApplier::class)->apply(
        $query,
        new FilterGroup(conditions: [$condition]),
        filterFields()
    );

    return $query->orderBy('id')->pluck('name')->all();
}

beforeEach(function () {
    $model = filterableModel();
    $model->newQuery()->delete();

    foreach ([
        ['name' => 'Acme Corporation', 'score' => 10, 'closes_on' => '2026-01-10', 'status' => 'open', 'is_active' => true],
        ['name' => 'Beta Industries', 'score' => 50, 'closes_on' => '2026-02-20', 'status' => 'won', 'is_active' => false],
        ['name' => 'Gamma Acme Ltd', 'score' => 90, 'closes_on' => '2026-03-30', 'status' => 'lost', 'is_active' => true],
        ['name' => '', 'score' => null, 'closes_on' => null, 'status' => null, 'is_active' => null],
    ] as $attributes) {
        $model->newQuery()->create($attributes);
    }
});

test('text contains matches anywhere in the value', function () {
    expect(matchNames(new FilterCondition('name', FilterOperator::Contains, 'Acme')))
        ->toBe(['Acme Corporation', 'Gamma Acme Ltd']);
});

test('text does not contain also keeps rows with no value', function () {
    expect(matchNames(new FilterCondition('name', FilterOperator::NotContains, 'Acme')))
        ->toBe(['Beta Industries', '']);
});

test('text equals matches the whole value only', function () {
    expect(matchNames(new FilterCondition('name', FilterOperator::Equals, 'Acme Corporation')))
        ->toBe(['Acme Corporation']);
});

test('text not equals keeps everything else, including blanks', function () {
    expect(matchNames(new FilterCondition('name', FilterOperator::NotEquals, 'Acme Corporation')))
        ->toBe(['Beta Industries', 'Gamma Acme Ltd', '']);
});

test('text starts with anchors to the beginning', function () {
    expect(matchNames(new FilterCondition('name', FilterOperator::StartsWith, 'Acme')))
        ->toBe(['Acme Corporation']);
});

test('text ends with anchors to the end', function () {
    expect(matchNames(new FilterCondition('name', FilterOperator::EndsWith, 'Ltd')))
        ->toBe(['Gamma Acme Ltd']);
});

test('number equals and not equals', function () {
    expect(matchNames(new FilterCondition('score', FilterOperator::Equals, 50)))->toBe(['Beta Industries'])
        ->and(matchNames(new FilterCondition('score', FilterOperator::NotEquals, 50)))
        ->toBe(['Acme Corporation', 'Gamma Acme Ltd', '']);
});

test('number comparisons are exclusive or inclusive as labelled', function () {
    expect(matchNames(new FilterCondition('score', FilterOperator::GreaterThan, 50)))->toBe(['Gamma Acme Ltd'])
        ->and(matchNames(new FilterCondition('score', FilterOperator::GreaterThanOrEqual, 50)))
        ->toBe(['Beta Industries', 'Gamma Acme Ltd'])
        ->and(matchNames(new FilterCondition('score', FilterOperator::LessThan, 50)))->toBe(['Acme Corporation'])
        ->and(matchNames(new FilterCondition('score', FilterOperator::LessThanOrEqual, 50)))
        ->toBe(['Acme Corporation', 'Beta Industries']);
});

test('number between includes both bounds', function () {
    expect(matchNames(new FilterCondition('score', FilterOperator::Between, 10, 50)))
        ->toBe(['Acme Corporation', 'Beta Industries']);
});

test('date on matches that day', function () {
    expect(matchNames(new FilterCondition('closes_on', FilterOperator::On, '2026-02-20')))
        ->toBe(['Beta Industries']);
});

test('date before and after exclude the day itself', function () {
    expect(matchNames(new FilterCondition('closes_on', FilterOperator::Before, '2026-02-20')))
        ->toBe(['Acme Corporation'])
        ->and(matchNames(new FilterCondition('closes_on', FilterOperator::After, '2026-02-20')))
        ->toBe(['Gamma Acme Ltd']);
});

test('date between includes both ends', function () {
    expect(matchNames(new FilterCondition('closes_on', FilterOperator::Between, '2026-01-10', '2026-02-20')))
        ->toBe(['Acme Corporation', 'Beta Industries']);
});

test('date within the last n days is measured from today', function () {
    $model = filterableModel();
    $model->newQuery()->create(['name' => 'Recent', 'closes_on' => now()->subDays(2)->toDateString()]);
    $model->newQuery()->create(['name' => 'Older', 'closes_on' => now()->subDays(30)->toDateString()]);

    expect(matchNames(new FilterCondition('closes_on', FilterOperator::LastDays, 7)))->toBe(['Recent']);
});

test('select is and is not', function () {
    expect(matchNames(new FilterCondition('status', FilterOperator::Equals, 'won')))->toBe(['Beta Industries'])
        ->and(matchNames(new FilterCondition('status', FilterOperator::NotEquals, 'won')))
        ->toBe(['Acme Corporation', 'Gamma Acme Ltd', '']);
});

test('select is any of and is none of', function () {
    expect(matchNames(new FilterCondition('status', FilterOperator::In, selected: ['open', 'lost'])))
        ->toBe(['Acme Corporation', 'Gamma Acme Ltd'])
        ->and(matchNames(new FilterCondition('status', FilterOperator::NotIn, selected: ['open', 'lost'])))
        ->toBe(['Beta Industries', '']);
});

test('boolean yes and no, where no covers never set', function () {
    expect(matchNames(new FilterCondition('is_active', FilterOperator::IsTrue)))
        ->toBe(['Acme Corporation', 'Gamma Acme Ltd'])
        ->and(matchNames(new FilterCondition('is_active', FilterOperator::IsFalse)))
        ->toBe(['Beta Industries', '']);
});

test('is empty covers both null and blank string', function () {
    expect(matchNames(new FilterCondition('name', FilterOperator::IsEmpty)))->toBe([''])
        ->and(matchNames(new FilterCondition('status', FilterOperator::IsEmpty)))->toBe(['']);
});

test('is not empty excludes null and blank', function () {
    expect(matchNames(new FilterCondition('name', FilterOperator::IsNotEmpty)))
        ->toBe(['Acme Corporation', 'Beta Industries', 'Gamma Acme Ltd']);
});

test('every operator offered for a field type can actually be applied', function (FilterFieldType $type) {
    $field = match ($type) {
        FilterFieldType::Text => 'name',
        FilterFieldType::Number => 'score',
        FilterFieldType::Date => 'closes_on',
        FilterFieldType::Select => 'status',
        FilterFieldType::Boolean => 'is_active',
    };

    $sample = match ($type) {
        FilterFieldType::Text => 'Acme',
        FilterFieldType::Number => 10,
        FilterFieldType::Date => '2026-01-10',
        FilterFieldType::Select => 'open',
        FilterFieldType::Boolean => true,
    };

    foreach ($type->operators() as $operator) {
        $condition = new FilterCondition(
            field: $field,
            operator: $operator,
            value: $operator === FilterOperator::LastDays ? 7 : $sample,
            secondValue: $sample,
            selected: [(string) $sample],
        );

        // Applying must not throw, whatever the operator/type pairing.
        expect(matchNames($condition))->toBeArray();
    }
})->with(FilterFieldType::cases());

test('conditions naming an unregistered field are ignored', function () {
    $model = filterableModel();
    $query = $model->newQuery();

    app(FilterApplier::class)->apply(
        $query,
        new FilterGroup(conditions: [new FilterCondition('secret_column', FilterOperator::Equals, 'x')]),
        filterFields()
    );

    // Nothing filtered out, and crucially no reference to the unknown column.
    expect($query->count())->toBe(4)
        ->and($query->toSql())->not->toContain('secret_column');
});

test('a condition using an operator its field type does not offer is ignored', function () {
    $model = filterableModel();
    $query = $model->newQuery();

    app(FilterApplier::class)->apply(
        $query,
        // "contains" is not offered for a boolean field.
        new FilterGroup(conditions: [new FilterCondition('is_active', FilterOperator::Contains, 'yes')]),
        filterFields()
    );

    expect($query->count())->toBe(4);
});

test('half-filled rows are ignored rather than matching everything', function () {
    expect(matchNames(new FilterCondition('name', FilterOperator::Contains, '')))
        ->toHaveCount(4)
        ->and(matchNames(new FilterCondition('score', FilterOperator::Between, 10)))
        ->toHaveCount(4)
        ->and(matchNames(new FilterCondition('status', FilterOperator::In, selected: [])))
        ->toHaveCount(4);
});

test('value modes describe how many inputs each operator needs', function () {
    expect(FilterOperator::IsEmpty->valueMode())->toBe(FilterValueMode::None)
        ->and(FilterOperator::IsEmpty->needsValue())->toBeFalse()
        ->and(FilterOperator::Contains->valueMode())->toBe(FilterValueMode::Single)
        ->and(FilterOperator::Between->valueMode())->toBe(FilterValueMode::Pair)
        ->and(FilterOperator::In->valueMode())->toBe(FilterValueMode::Multiple);
});

test('every operator has a label and appears for at least one field type', function (FilterOperator $operator) {
    $offeredSomewhere = collect(FilterFieldType::cases())
        ->contains(fn (FilterFieldType $type) => in_array($operator, $type->operators(), true));

    expect($operator->label())->not->toBeEmpty()
        ->and($offeredSomewhere)->toBeTrue();
})->with(FilterOperator::cases());
