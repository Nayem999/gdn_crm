<?php

use App\Domain\Leads\Actions\ChangeLeadStatusAction;
use App\Domain\Leads\Actions\CreateLeadAction;
use App\Domain\Leads\Actions\SaveLeadScoringRulesAction;
use App\Domain\Leads\Actions\ScoreLeadsAction;
use App\Domain\Leads\Actions\UpdateLeadAction;
use App\Domain\Leads\DTOs\LeadData;
use App\Domain\Leads\DTOs\LeadScore;
use App\Domain\Leads\Enums\LeadGrade;
use App\Domain\Leads\Enums\LeadRuleKind;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\LeadFields;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadScoringRule;
use App\Domain\Leads\Services\LeadScoring;
use App\Domain\Shared\Enums\FilterOperator;
use App\Domain\Shared\Filters\FilterApplier;
use App\Domain\Shared\Filters\FilterGroup;
use App\Domain\Shared\UI\ChipPalette;
use App\Jobs\RescoreLeads;
use App\Models\User;
use Database\Seeders\LeadScoringRulesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

/**
 * A rule of the "field operator value" shape, worth some points.
 *
 * @param  array<int, string>  $selected
 */
function scoringRule(
    string $field,
    FilterOperator $operator,
    int $points,
    mixed $value = null,
    mixed $secondValue = null,
    array $selected = [],
): LeadScoringRule {
    return LeadScoringRule::factory()
        ->condition($field, $operator, $value, $secondValue, $selected)
        ->worth($points)
        ->create(['label' => $field.' '.$operator->value]);
}

function scoreOf(Lead $lead): int
{
    app(ScoreLeadsAction::class)(Lead::query()->whereKey($lead->getKey()));

    return (int) $lead->fresh()?->score;
}

afterEach(function () {
    Carbon::setTestNow();
});

// -- Grades --------------------------------------------------------------------

test('every grade has a label, description, palette colour and option', function (LeadGrade $grade) {
    expect($grade->label())->not->toBeEmpty()
        ->and($grade->description())->not->toBeEmpty()
        ->and(ChipPalette::has($grade->color()))->toBeTrue()
        ->and(LeadGrade::options())->toHaveKey($grade->value);
})->with(LeadGrade::cases());

test('the grade bands are listed from the top down with no equal thresholds', function () {
    $thresholds = array_map(fn (LeadGrade $grade) => $grade->threshold(), LeadGrade::descending());

    expect($thresholds)->toBe([75, 50, 25, 0])
        ->and(LeadGrade::descending())->toEqualCanonicalizing(LeadGrade::cases());
});

test('every score in range lands in a band', function (int $score) {
    expect(LeadGrade::forScore($score))->toBeInstanceOf(LeadGrade::class);
})->with(range(0, 100));

test('the band boundaries are where the thresholds say', function (int $score, LeadGrade $expected) {
    expect(LeadGrade::forScore($score))->toBe($expected);
})->with([
    'floor' => [0, LeadGrade::Cold],
    'top of cold' => [24, LeadGrade::Cold],
    'bottom of cool' => [25, LeadGrade::Cool],
    'top of cool' => [49, LeadGrade::Cool],
    'bottom of warm' => [50, LeadGrade::Warm],
    'top of warm' => [74, LeadGrade::Warm],
    'bottom of hot' => [75, LeadGrade::Hot],
    'ceiling' => [100, LeadGrade::Hot],
]);

test('every rule kind has a label, description and option', function (LeadRuleKind $kind) {
    expect($kind->label())->not->toBeEmpty()
        ->and($kind->description())->not->toBeEmpty()
        ->and(LeadRuleKind::options())->toHaveKey($kind->value);
})->with(LeadRuleKind::cases());

// -- The score value object ----------------------------------------------------

test('a score is held to the 0-100 range', function (int $points, int $expected, bool $clamped) {
    $score = LeadScore::from($points);

    expect($score->points)->toBe($points)
        ->and($score->score)->toBe($expected)
        ->and($score->wasClamped())->toBe($clamped)
        ->and($score->grade)->toBe(LeadGrade::forScore($expected));
})->with([
    'in range' => [42, 42, false],
    'exactly the ceiling' => [100, 100, false],
    'over the ceiling' => [180, 100, true],
    'exactly the floor' => [0, 0, false],
    'below the floor' => [-40, 0, true],
]);

// -- What a rule can and cannot evaluate ---------------------------------------

test('a rule naming a field the leads list does not offer is unusable and matches nothing', function () {
    $lead = Lead::factory()->create();
    scoringRule('secret_column', FilterOperator::IsNotEmpty, 50);

    expect(LeadScoringRule::first()?->isUsable())->toBeFalse()
        ->and(scoreOf($lead))->toBe(0);
});

test('a rule left without the value its comparison needs matches nothing', function () {
    $lead = Lead::factory()->create(['company_name' => 'Acme']);
    scoringRule('company_name', FilterOperator::Contains, 50, value: null);

    expect(LeadScoringRule::first()?->isUsable())->toBeFalse()
        ->and(scoreOf($lead))->toBe(0);
});

test('a rule reads as a phrase, and says so when its field has gone', function () {
    $good = scoringRule('estimated_value', FilterOperator::GreaterThanOrEqual, 10, value: '5000');
    $stale = scoringRule('gone_away', FilterOperator::Equals, 10, value: 'x');

    expect($good->summary())->toContain('Estimated value')
        ->and($good->summary())->toContain('5000')
        ->and($stale->summary())->toContain('Unknown field');
});

// -- Scoring, rule by rule -----------------------------------------------------

test('with no rules configured every lead scores nothing', function () {
    $lead = Lead::factory()->create();

    expect(scoreOf($lead))->toBe(0)
        ->and($lead->fresh()?->grade())->toBe(LeadGrade::Cold);
});

test('a matching rule adds its points and a non-matching one adds nothing', function () {
    $lead = Lead::factory()->create(['company_name' => 'Acme', 'city' => null]);

    scoringRule('company_name', FilterOperator::IsNotEmpty, 30);
    scoringRule('city', FilterOperator::IsNotEmpty, 40);

    expect(scoreOf($lead))->toBe(30);
});

test('an inactive rule is ignored', function () {
    $lead = Lead::factory()->create(['company_name' => 'Acme']);

    LeadScoringRule::factory()
        ->condition('company_name', FilterOperator::IsNotEmpty)
        ->worth(40)
        ->inactive()
        ->create();

    expect(scoreOf($lead))->toBe(0);
});

test('a qualification requirement never contributes points', function () {
    $lead = Lead::factory()->create(['company_name' => 'Acme']);

    LeadScoringRule::factory()
        ->condition('company_name', FilterOperator::IsNotEmpty)
        ->requirement()
        ->create(['points' => 50]);

    expect(scoreOf($lead))->toBe(0);
});

// -- Combinations, which is what the task is really about ----------------------

test('the score is the sum of every rule the lead matches', function (
    array $attributes,
    array $rules,
    int $expected,
    LeadGrade $grade,
) {
    foreach ($rules as $rule) {
        scoringRule(...$rule);
    }

    $lead = Lead::factory()->create($attributes);

    expect(scoreOf($lead))->toBe($expected)
        ->and($lead->fresh()?->grade())->toBe($grade);
})->with([
    'nothing matches' => [
        ['company_name' => null, 'phone' => null, 'estimated_value' => null],
        [
            ['company_name', FilterOperator::IsNotEmpty, 20],
            ['phone', FilterOperator::IsNotEmpty, 20],
        ],
        0,
        LeadGrade::Cold,
    ],
    'one of two matches' => [
        ['company_name' => 'Acme', 'phone' => null],
        [
            ['company_name', FilterOperator::IsNotEmpty, 20],
            ['phone', FilterOperator::IsNotEmpty, 20],
        ],
        20,
        LeadGrade::Cold,
    ],
    'both match' => [
        ['company_name' => 'Acme', 'phone' => '0123'],
        [
            ['company_name', FilterOperator::IsNotEmpty, 20],
            ['phone', FilterOperator::IsNotEmpty, 20],
        ],
        40,
        LeadGrade::Cool,
    ],
    'three across different field types' => [
        ['company_name' => 'Acme', 'estimated_value' => '25000.00', 'source' => 'referral'],
        [
            ['company_name', FilterOperator::IsNotEmpty, 10],
            ['estimated_value', FilterOperator::GreaterThanOrEqual, 25, '10000'],
            ['source', FilterOperator::Equals, 20, 'referral'],
        ],
        55,
        LeadGrade::Warm,
    ],
    'overlapping thresholds both count' => [
        ['estimated_value' => '80000.00'],
        [
            ['estimated_value', FilterOperator::GreaterThan, 20, '0'],
            ['estimated_value', FilterOperator::GreaterThanOrEqual, 30, '10000'],
            ['estimated_value', FilterOperator::GreaterThanOrEqual, 30, '50000'],
        ],
        80,
        LeadGrade::Hot,
    ],
    'a negative rule takes points away' => [
        ['company_name' => 'Acme', 'email' => 'someone@gmail.com'],
        [
            ['company_name', FilterOperator::IsNotEmpty, 60],
            ['email', FilterOperator::Contains, -20, 'gmail.com'],
        ],
        40,
        LeadGrade::Cool,
    ],
    'the total is held at 100' => [
        ['company_name' => 'Acme', 'phone' => '0123', 'email' => 'a@b.test'],
        [
            ['company_name', FilterOperator::IsNotEmpty, 60],
            ['phone', FilterOperator::IsNotEmpty, 60],
            ['email', FilterOperator::IsNotEmpty, 60],
        ],
        100,
        LeadGrade::Hot,
    ],
    'the total is held at 0 when the negatives win' => [
        ['company_name' => 'Acme', 'email' => 'someone@gmail.com'],
        [
            ['company_name', FilterOperator::IsNotEmpty, 10],
            ['email', FilterOperator::Contains, -90, 'gmail.com'],
        ],
        0,
        LeadGrade::Cold,
    ],
]);

test('rules work on every field type the filter builder offers', function (
    array $attributes,
    string $field,
    FilterOperator $operator,
    mixed $value,
    mixed $secondValue,
    array $selected,
    bool $shouldMatch,
) {
    scoringRule($field, $operator, 40, $value, $secondValue, $selected);
    $lead = Lead::factory()->create($attributes);

    expect(scoreOf($lead))->toBe($shouldMatch ? 40 : 0);
})->with([
    'text contains' => [['company_name' => 'Acme Industries'], 'company_name', FilterOperator::Contains, 'Indus', null, [], true],
    'text does not contain' => [['company_name' => 'Acme'], 'company_name', FilterOperator::NotContains, 'Indus', null, [], true],
    'text starts with' => [['last_name' => 'Marchetti'], 'last_name', FilterOperator::StartsWith, 'March', null, [], true],
    'text is empty' => [['city' => null], 'city', FilterOperator::IsEmpty, null, null, [], true],
    'number at least' => [['estimated_value' => '9000.00'], 'estimated_value', FilterOperator::GreaterThanOrEqual, '9000', null, [], true],
    'number below the bar' => [['estimated_value' => '8999.00'], 'estimated_value', FilterOperator::GreaterThanOrEqual, '9000', null, [], false],
    'number between' => [['estimated_value' => '5000.00'], 'estimated_value', FilterOperator::Between, '1000', '9000', [], true],
    'select is' => [['source' => 'referral'], 'source', FilterOperator::Equals, 'referral', null, [], true],
    'select is any of' => [['status' => 'contacted'], 'status', FilterOperator::In, null, null, ['contacted', 'nurturing'], true],
    'select is none of' => [['status' => 'new'], 'status', FilterOperator::NotIn, null, null, ['contacted', 'nurturing'], true],
]);

test('a date rule scores on how recently something happened', function () {
    scoringRule('created_at', FilterOperator::LastDays, 35, '7');

    $recent = Lead::factory()->create(['created_at' => now()->subDays(2)]);
    $old = Lead::factory()->create(['created_at' => now()->subDays(30)]);

    expect(scoreOf($recent))->toBe(35)
        ->and(scoreOf($old))->toBe(0);
});

// -- The reason scoring runs in SQL --------------------------------------------

test('a rule matches exactly the leads the same condition matches as a list filter', function (
    string $field,
    FilterOperator $operator,
    mixed $value,
    array $selected,
) {
    Lead::factory()->count(3)->create(['company_name' => 'Acme Industries', 'city' => 'Bristol', 'source' => 'referral']);
    Lead::factory()->count(3)->create(['company_name' => null, 'city' => null, 'source' => 'chat']);
    Lead::factory()->count(2)->create(['company_name' => 'Other Ltd', 'city' => 'Leeds', 'source' => 'partner']);

    $rule = scoringRule($field, $operator, 10, $value, selected: $selected);

    app(ScoreLeadsAction::class)();

    $scored = Lead::query()->where('score', '>', 0)->orderBy('id')->pluck('id')->all();

    $filtered = app(FilterApplier::class)->apply(
        Lead::query(),
        new FilterGroup(conditions: [$rule->condition()]),
        LeadFields::filters()
    )->orderBy('id')->pluck('id')->all();

    expect($scored)->toBe($filtered)->and($filtered)->not->toBeEmpty();
})->with([
    'contains' => ['company_name', FilterOperator::Contains, 'Acme', []],
    // The NULL-aware negations are the ones a second, in-PHP evaluator would
    // most easily get wrong.
    'does not contain' => ['company_name', FilterOperator::NotContains, 'Acme', []],
    'is not' => ['city', FilterOperator::NotEquals, 'Bristol', []],
    'is empty' => ['city', FilterOperator::IsEmpty, null, []],
    'is none of' => ['source', FilterOperator::NotIn, null, ['referral']],
]);

// -- When scoring happens ------------------------------------------------------

test('a lead is scored as it is captured', function () {
    scoringRule('company_name', FilterOperator::IsNotEmpty, 30);
    $actor = User::factory()->create();

    $lead = app(CreateLeadAction::class)(new LeadData(
        firstName: 'Dara',
        lastName: 'Okafor',
        companyName: 'Acme',
        email: 'dara@acme.test',
    ), $actor);

    expect($lead->score)->toBe(30)
        ->and($lead->scored_at)->not->toBeNull();
});

test('an edit rescores the lead', function () {
    scoringRule('estimated_value', FilterOperator::GreaterThanOrEqual, 45, '10000');
    $lead = Lead::factory()->create(['estimated_value' => '500.00']);

    expect(scoreOf($lead))->toBe(0);

    $updated = app(UpdateLeadAction::class)($lead->fresh(), new LeadData(
        firstName: $lead->first_name,
        lastName: $lead->last_name,
        email: $lead->email,
        estimatedValue: '20000',
    ));

    expect($updated->score)->toBe(45);
});

test('a status change rescores the lead, because status is a field a rule can read', function () {
    scoringRule('status', FilterOperator::In, 25, selected: [LeadStatus::Contacted->value]);
    $lead = Lead::factory()->create();

    expect(scoreOf($lead))->toBe(0);

    app(ChangeLeadStatusAction::class)($lead->fresh(), LeadStatus::Contacted);

    expect((int) $lead->fresh()?->score)->toBe(25);
});

test('rescoring the database lowers a score that no longer earns its points', function () {
    $rule = scoringRule('company_name', FilterOperator::IsNotEmpty, 70);
    $lead = Lead::factory()->create(['company_name' => 'Acme']);

    expect(scoreOf($lead))->toBe(70);

    $rule->delete();
    app(ScoreLeadsAction::class)();

    expect((int) $lead->fresh()?->score)->toBe(0);
});

test('rescoring covers every lead and reports how many', function () {
    scoringRule('source', FilterOperator::Equals, 60, LeadSource::Referral->value);

    Lead::factory()->count(4)->source(LeadSource::Referral)->create();
    Lead::factory()->count(3)->source(LeadSource::Chat)->create();

    expect(app(ScoreLeadsAction::class)())->toBe(7)
        ->and(Lead::query()->where('score', 60)->count())->toBe(4)
        ->and(Lead::query()->where('score', 0)->count())->toBe(3)
        ->and(Lead::query()->whereNull('scored_at')->count())->toBe(0);
});

test('the score column is not something a form can write', function () {
    expect((new Lead)->getFillable())->not->toContain('score');
});

// -- Scoring must stay quiet ---------------------------------------------------

test('rescoring writes nothing to the audit trail', function () {
    scoringRule('company_name', FilterOperator::IsNotEmpty, 30);
    Lead::factory()->count(3)->create(['company_name' => 'Acme']);

    Activity::query()->delete();
    app(ScoreLeadsAction::class)();

    expect(Activity::query()->count())->toBe(0);
});

test('score is deliberately absent from the audited attributes', function () {
    $method = new ReflectionMethod(Lead::class, 'activityAttributes');

    expect($method->invoke(new Lead))->not->toContain('score');
});

test('rescoring does not touch updated_at', function () {
    Carbon::setTestNow('2026-01-01 09:00:00');
    scoringRule('company_name', FilterOperator::IsNotEmpty, 30);
    $lead = Lead::factory()->create(['company_name' => 'Acme']);
    $before = $lead->updated_at;

    Carbon::setTestNow('2026-02-01 09:00:00');
    app(ScoreLeadsAction::class)();

    expect($lead->fresh()?->updated_at?->toDateTimeString())->toBe($before?->toDateTimeString())
        ->and($lead->fresh()?->scored_at?->toDateTimeString())->toBe('2026-02-01 09:00:00');
});

test('scoring the whole database costs queries per rule, not per lead', function () {
    scoringRule('company_name', FilterOperator::IsNotEmpty, 10);
    scoringRule('phone', FilterOperator::IsNotEmpty, 10);
    scoringRule('email', FilterOperator::IsNotEmpty, 10);

    Lead::factory()->count(40)->create();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    app(ScoreLeadsAction::class)();

    // Three rules, the id sweep, the rule lookup, the lead-owner options the
    // field list carries, and one update per distinct score. Nowhere near one
    // round trip per lead.
    expect($queries)->toBeLessThan(16);
});

// -- The rescore job -----------------------------------------------------------

test('the rescore job scores every lead', function () {
    scoringRule('company_name', FilterOperator::IsNotEmpty, 55);
    Lead::factory()->count(2)->create(['company_name' => 'Acme']);

    (new RescoreLeads)->handle(app(ScoreLeadsAction::class));

    expect(Lead::query()->where('score', 55)->count())->toBe(2);
});

test('saving the rules queues a rescore', function () {
    Queue::fake();

    app(SaveLeadScoringRulesAction::class)([[
        'kind' => LeadRuleKind::Score->value,
        'label' => 'Has a company',
        'field' => 'company_name',
        'operator' => FilterOperator::IsNotEmpty->value,
        'points' => 15,
        'is_active' => true,
    ]]);

    Queue::assertPushed(RescoreLeads::class);
});

// -- The save action is a registry gate ----------------------------------------

test('a submitted rule naming something outside the field registry is dropped', function (array $row) {
    expect(app(SaveLeadScoringRulesAction::class)([$row]))->toBe(0)
        ->and(LeadScoringRule::query()->count())->toBe(0);
})->with([
    'unregistered field' => [[
        'kind' => 'score', 'label' => 'Sneaky', 'field' => 'password',
        'operator' => 'is_not_empty', 'points' => 90, 'is_active' => true,
    ]],
    'operator the field type does not offer' => [[
        'kind' => 'score', 'label' => 'Sneaky', 'field' => 'estimated_value',
        'operator' => 'starts_with', 'value' => '1', 'points' => 90, 'is_active' => true,
    ]],
    'unknown kind' => [[
        'kind' => 'sabotage', 'label' => 'Sneaky', 'field' => 'company_name',
        'operator' => 'is_not_empty', 'points' => 90, 'is_active' => true,
    ]],
    'no label' => [[
        'kind' => 'score', 'label' => '  ', 'field' => 'company_name',
        'operator' => 'is_not_empty', 'points' => 90, 'is_active' => true,
    ]],
]);

test('a scoring rule may not read the score, but a requirement may', function () {
    $stored = app(SaveLeadScoringRulesAction::class)([
        [
            'kind' => LeadRuleKind::Score->value, 'label' => 'Circular',
            'field' => LeadFields::SCORE, 'operator' => FilterOperator::GreaterThan->value,
            'value' => '10', 'points' => 50, 'is_active' => true,
        ],
        [
            'kind' => LeadRuleKind::Qualification->value, 'label' => 'Scores at least 50',
            'field' => LeadFields::SCORE, 'operator' => FilterOperator::GreaterThanOrEqual->value,
            'value' => '50', 'is_active' => true,
        ],
    ]);

    expect($stored)->toBe(1)
        ->and(LeadScoringRule::query()->pluck('kind')->all())->toBe([LeadRuleKind::Qualification->value]);
});

test('chosen options outside the field list are dropped', function () {
    app(SaveLeadScoringRulesAction::class)([[
        'kind' => 'score', 'label' => 'Worked statuses', 'field' => 'status',
        'operator' => FilterOperator::In->value,
        'selected' => ['contacted', 'not_a_status'],
        'points' => 10, 'is_active' => true,
    ]]);

    expect(LeadScoringRule::first()?->selected)->toBe(['contacted']);
});

test('points are zeroed on a requirement, which is pass or fail', function () {
    app(SaveLeadScoringRulesAction::class)([[
        'kind' => LeadRuleKind::Qualification->value, 'label' => 'Has a company',
        'field' => 'company_name', 'operator' => FilterOperator::IsNotEmpty->value,
        'points' => 99, 'is_active' => true,
    ]]);

    expect(LeadScoringRule::first()?->points)->toBe(0);
});

test('saving keeps a rule identity by id, deletes what vanished, and orders by submission', function () {
    $kept = scoringRule('company_name', FilterOperator::IsNotEmpty, 10);
    $dropped = scoringRule('phone', FilterOperator::IsNotEmpty, 10);

    app(SaveLeadScoringRulesAction::class)([
        [
            'id' => null, 'kind' => 'score', 'label' => 'Brand new',
            'field' => 'email', 'operator' => FilterOperator::IsNotEmpty->value,
            'points' => 5, 'is_active' => true,
        ],
        [
            'id' => $kept->id, 'kind' => 'score', 'label' => 'Renamed',
            'field' => 'company_name', 'operator' => FilterOperator::IsNotEmpty->value,
            'points' => 25, 'is_active' => false,
        ],
    ]);

    $kept->refresh();

    expect(LeadScoringRule::query()->count())->toBe(2)
        ->and(LeadScoringRule::query()->whereKey($dropped->id)->exists())->toBeFalse()
        ->and($kept->label)->toBe('Renamed')
        ->and($kept->points)->toBe(25)
        ->and($kept->is_active)->toBeFalse()
        ->and($kept->position)->toBe(1)
        ->and(LeadScoringRule::query()->where('position', 0)->value('label'))->toBe('Brand new');
});

test('changing a rule is on the audit trail', function () {
    $this->actingAs(User::factory()->create());

    $rule = scoringRule('company_name', FilterOperator::IsNotEmpty, 10);
    $rule->update(['points' => 40]);

    $entry = Activity::query()->where('subject_type', LeadScoringRule::class)->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry?->properties['old']['points'] ?? null)->toEqual(10)
        ->and($entry?->properties['attributes']['points'] ?? null)->toEqual(40);
});

// -- The starter rule set ------------------------------------------------------

test('the seeder installs a starter scoring model and leaves qualification open', function () {
    (new LeadScoringRulesSeeder)->run();

    expect(LeadScoringRule::query()->ofKind(LeadRuleKind::Score)->count())->toBeGreaterThan(5)
        ->and(LeadScoringRule::query()->ofKind(LeadRuleKind::Qualification)->count())->toBe(0);
});

test('every seeded rule is one the engine can actually evaluate', function () {
    (new LeadScoringRulesSeeder)->run();

    LeadScoringRule::query()->get()->each(function (LeadScoringRule $rule) {
        expect($rule->isUsable())->toBeTrue("{$rule->label} is not evaluable");
    });
});

test('the seeder leaves a configured installation alone', function () {
    scoringRule('company_name', FilterOperator::IsNotEmpty, 10);

    (new LeadScoringRulesSeeder)->run();

    expect(LeadScoringRule::query()->count())->toBe(1);
});

test('the starter model scores a strong lead well above a bare one', function () {
    (new LeadScoringRulesSeeder)->run();

    $strong = Lead::factory()->source(LeadSource::Referral)->create([
        'company_name' => 'Acme Industries',
        'job_title' => 'Head of Operations',
        'email' => 'dara@acme.test',
        'phone' => '+44 117 000 0000',
        'estimated_value' => '45000.00',
    ]);

    $bare = Lead::factory()->source(LeadSource::Chat)->create([
        'company_name' => null,
        'job_title' => null,
        'email' => 'someone@gmail.com',
        'phone' => null,
        'estimated_value' => null,
    ]);

    expect(scoreOf($strong))->toBeGreaterThan(scoreOf($bare))
        ->and($strong->fresh()?->grade())->toBe(LeadGrade::Hot);
});

test('under the starter model, qualifying a lead does not cost it points', function () {
    (new LeadScoringRulesSeeder)->run();

    $lead = Lead::factory()->status(LeadStatus::Contacted)->create([
        'company_name' => 'Acme Industries',
        'email' => 'dara@acme.test',
        'phone' => '+44 117 000 0000',
    ]);

    $before = scoreOf($lead);

    app(ChangeLeadStatusAction::class)($lead->fresh(), LeadStatus::Qualified);

    // Status is a scoring input, so a rule covering only the earlier statuses
    // would quietly penalise progress.
    expect((int) $lead->fresh()?->score)->toBe($before);
});

// -- The breakdown the detail page shows ---------------------------------------

test('the breakdown names the rules that fired, in rule order, and nothing else', function () {
    LeadScoringRule::factory()->condition('company_name', FilterOperator::IsNotEmpty)->worth(20)->at(0)
        ->create(['label' => 'Has a company']);
    LeadScoringRule::factory()->condition('city', FilterOperator::IsNotEmpty)->worth(15)->at(1)
        ->create(['label' => 'Has a city']);
    LeadScoringRule::factory()->condition('phone', FilterOperator::IsNotEmpty)->worth(10)->at(2)
        ->create(['label' => 'Has a phone']);

    $lead = Lead::factory()->create(['company_name' => 'Acme', 'city' => null, 'phone' => '0123']);

    $breakdown = app(LeadScoring::class)->scoreFor($lead);

    expect($breakdown->score)->toBe(30)
        ->and(array_column($breakdown->matched, 'label'))->toBe(['Has a company', 'Has a phone'])
        ->and(array_column($breakdown->matched, 'points'))->toBe([20, 10]);
});
