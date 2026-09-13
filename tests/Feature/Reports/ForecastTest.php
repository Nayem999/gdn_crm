<?php

use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\Pipeline;
use App\Domain\Reports\Forecasting\ForecastCalculator;
use App\Domain\Reports\Forecasting\ForecastPeriod;
use App\Livewire\Reports\SalesForecast;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * A pipeline whose stages carry known probabilities, so the weighting can be
 * asserted exactly rather than against whatever the seeder chose.
 */
function forecastPipeline(): Pipeline
{
    $pipeline = Pipeline::factory()->create(['name' => 'Forecast test '.uniqid()]);

    $pipeline->stages()->delete();

    foreach ([
        ['key' => 'qualification', 'name' => 'Qualification', 'probability' => 20, 'outcome' => 'open', 'position' => 0],
        ['key' => 'proposal', 'name' => 'Proposal', 'probability' => 50, 'outcome' => 'open', 'position' => 1],
        ['key' => 'negotiation', 'name' => 'Negotiation', 'probability' => 80, 'outcome' => 'open', 'position' => 2],
        ['key' => 'won', 'name' => 'Won', 'probability' => 100, 'outcome' => 'won', 'position' => 3],
        ['key' => 'lost', 'name' => 'Lost', 'probability' => 0, 'outcome' => 'lost', 'position' => 4],
    ] as $stage) {
        $pipeline->stages()->create($stage);
    }

    return $pipeline->fresh('stages');
}

/**
 * An open deal expected to close on a given day.
 */
function forecastDeal(User $owner, Pipeline $pipeline, string $stage, float $value, string $expectedClose): Deal
{
    return Deal::factory()->ownedBy($owner)->create([
        'pipeline_id' => $pipeline->id,
        'stage' => $stage,
        'value' => $value,
        'expected_close_date' => $expectedClose,
        'closed_at' => null,
    ]);
}

/**
 * A deal already won, closed on a given day.
 */
function wonDeal(User $owner, Pipeline $pipeline, float $value, string $closedAt): Deal
{
    return Deal::factory()->ownedBy($owner)->create([
        'pipeline_id' => $pipeline->id,
        'stage' => DealStage::Won->value,
        'value' => $value,
        'closed_at' => $closedAt,
        'expected_close_date' => $closedAt,
    ]);
}

beforeEach(function () {
    // The middle of a month, so "this month" is half over and the history
    // window is unambiguous.
    Carbon::setTestNow('2026-09-15 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

// -- The periods ---------------------------------------------------------------

test('a forecast covers whole months from this one forward', function () {
    $periods = ForecastPeriod::ahead(3);

    expect($periods)->toHaveCount(3)
        ->and($periods[0]->label)->toBe('2026-09')
        ->and($periods[0]->from->format('Y-m-d H:i:s'))->toBe('2026-09-01 00:00:00')
        ->and($periods[0]->to->format('Y-m-d'))->toBe('2026-09-30')
        ->and($periods[2]->label)->toBe('2026-11');
});

test('the history window is whole months before this one', function () {
    $periods = ForecastPeriod::behind(6);

    // The current month is left out: it is half over, and averaging a
    // part-month in with whole ones drags every historical figure down.
    expect($periods)->toHaveCount(6)
        ->and($periods[0]->label)->toBe('2026-03')
        ->and($periods[5]->label)->toBe('2026-08')
        ->and(array_column($periods, 'label'))->not->toContain('2026-09');
});

// -- Pipeline-weighted ----------------------------------------------------------

test('each open deal is counted at its stage probability', function () {
    $viewer = reportAdmin();
    $pipeline = forecastPipeline();

    forecastDeal($viewer, $pipeline, 'qualification', 1000, '2026-09-20');  // 20% = 200
    forecastDeal($viewer, $pipeline, 'proposal', 1000, '2026-09-21');       // 50% = 500
    forecastDeal($viewer, $pipeline, 'negotiation', 1000, '2026-09-22');    // 80% = 800

    $forecast = app(ForecastCalculator::class)->forecast($viewer, ForecastPeriod::ahead(1))[0];

    expect($forecast->weighted)->toBe(1500.0)
        ->and($forecast->openCount)->toBe(3)
        // Best case is every one of them landing.
        ->and($forecast->bestCase)->toBe(3000.0);
});

test('a deal is forecast in the month it is expected to close', function () {
    $viewer = reportAdmin();
    $pipeline = forecastPipeline();

    forecastDeal($viewer, $pipeline, 'proposal', 1000, '2026-09-30');
    forecastDeal($viewer, $pipeline, 'proposal', 400, '2026-10-01');

    $forecasts = app(ForecastCalculator::class)->forecast($viewer, ForecastPeriod::ahead(2));

    // By expected_close_date — the salesperson's own answer to "when will this
    // land", not the application's guess.
    expect($forecasts[0]->weighted)->toBe(500.0)
        ->and($forecasts[1]->weighted)->toBe(200.0);
});

test('a won deal is committed, not forecast twice', function () {
    $viewer = reportAdmin();
    $pipeline = forecastPipeline();

    wonDeal($viewer, $pipeline, 1000, '2026-09-10');
    forecastDeal($viewer, $pipeline, 'proposal', 800, '2026-09-25');

    $forecast = app(ForecastCalculator::class)->forecast($viewer, ForecastPeriod::ahead(1))[0];

    expect($forecast->committed)->toBe(1000.0)
        ->and($forecast->weighted)->toBe(400.0)
        ->and($forecast->expected())->toBe(1400.0)
        // The won deal is in committed and in best case, and counted once in
        // each — not added to the weighted pipeline as well.
        ->and($forecast->bestCase)->toBe(1800.0);
});

test('a lost deal is in neither forecast', function () {
    $viewer = reportAdmin();
    $pipeline = forecastPipeline();

    Deal::factory()->ownedBy($viewer)->create([
        'pipeline_id' => $pipeline->id,
        'stage' => DealStage::Lost->value,
        'value' => 9999,
        'expected_close_date' => '2026-09-20',
        'closed_at' => '2026-09-11',
    ]);

    $forecast = app(ForecastCalculator::class)->forecast($viewer, ForecastPeriod::ahead(1))[0];

    expect($forecast->weighted)->toBe(0.0)
        ->and($forecast->committed)->toBe(0.0)
        ->and($forecast->bestCase)->toBe(0.0);
});

test('a deal on no pipeline falls back to the stage default', function () {
    $viewer = reportAdmin();

    $deal = Deal::factory()->ownedBy($viewer)->create([
        'pipeline_id' => null,
        'stage' => 'proposal',
        'value' => 1000,
        'expected_close_date' => '2026-09-20',
        'closed_at' => null,
    ]);

    $forecast = app(ForecastCalculator::class)->forecast($viewer, ForecastPeriod::ahead(1))[0];

    // The same fallback Deal::weightedValue() uses, so the forecast and the
    // deal page cannot disagree.
    expect($forecast->weighted)->toBe($deal->weightedValue());
});

// -- Historical ----------------------------------------------------------------

test('the historical average is the mean of the last whole months', function () {
    $viewer = reportAdmin();
    $pipeline = forecastPipeline();

    // 600 in one month, 300 in another, nothing in the other four.
    wonDeal($viewer, $pipeline, 600, '2026-08-10');
    wonDeal($viewer, $pipeline, 300, '2026-07-10');

    $calculator = app(ForecastCalculator::class);

    // 900 over six months. A quiet month is evidence, so the empty ones count.
    expect($calculator->historicalAverage($viewer))->toBe(150.0)
        ->and($calculator->history($viewer))->toHaveCount(ForecastCalculator::HISTORY_MONTHS);
});

test('the history is the months themselves, in order', function () {
    $viewer = reportAdmin();
    $pipeline = forecastPipeline();

    wonDeal($viewer, $pipeline, 500, '2026-08-10');

    $history = app(ForecastCalculator::class)->history($viewer);

    expect(array_key_first($history))->toBe('2026-03')
        ->and(array_key_last($history))->toBe('2026-08')
        ->and($history['2026-08'])->toBe(500.0)
        ->and($history['2026-07'])->toBe(0.0);
});

test('this half-finished month is not in the history', function () {
    $viewer = reportAdmin();
    $pipeline = forecastPipeline();

    wonDeal($viewer, $pipeline, 6000, '2026-09-02');

    // Averaging a part-month in with whole ones would drag the figure down and
    // make every forecast look optimistic.
    expect(app(ForecastCalculator::class)->history($viewer))->not->toHaveKey('2026-09')
        ->and(app(ForecastCalculator::class)->historicalAverage($viewer))->toBe(0.0);
});

test('no history at all is nought rather than a division by zero', function () {
    expect(app(ForecastCalculator::class)->historicalAverage(reportAdmin()))->toBe(0.0);
});

// -- The gap between the two ----------------------------------------------------

test('the gap against a typical month is reported, and flagged when it is wide', function () {
    $viewer = reportAdmin();
    $pipeline = forecastPipeline();

    // Six months at 600 each: a typical month is 600.
    foreach (['2026-03-10', '2026-04-10', '2026-05-10', '2026-06-10', '2026-07-10', '2026-08-10'] as $day) {
        wonDeal($viewer, $pipeline, 600, $day);
    }

    // A pipeline promising 800 weighted this month.
    forecastDeal($viewer, $pipeline, 'negotiation', 1000, '2026-09-20');

    $forecast = app(ForecastCalculator::class)->forecast($viewer, ForecastPeriod::ahead(1))[0];

    expect($forecast->historical)->toBe(600.0)
        ->and($forecast->expected())->toBe(800.0)
        // A third above a typical month.
        ->and($forecast->versusHistory())->toBe(33.3)
        ->and($forecast->isOptimistic())->toBeTrue();
});

test('a forecast in line with history is not flagged', function () {
    $viewer = reportAdmin();
    $pipeline = forecastPipeline();

    foreach (['2026-03-10', '2026-04-10', '2026-05-10', '2026-06-10', '2026-07-10', '2026-08-10'] as $day) {
        wonDeal($viewer, $pipeline, 600, $day);
    }

    forecastDeal($viewer, $pipeline, 'negotiation', 750, '2026-09-20'); // 600 weighted

    $forecast = app(ForecastCalculator::class)->forecast($viewer, ForecastPeriod::ahead(1))[0];

    expect($forecast->versusHistory())->toBe(0.0)
        ->and($forecast->isOptimistic())->toBeFalse();
});

test('with no history there is no gap to report rather than an infinite one', function () {
    $viewer = reportAdmin();
    $pipeline = forecastPipeline();

    forecastDeal($viewer, $pipeline, 'negotiation', 1000, '2026-09-20');

    $forecast = app(ForecastCalculator::class)->forecast($viewer, ForecastPeriod::ahead(1))[0];

    expect($forecast->historical)->toBe(0.0)
        ->and($forecast->versusHistory())->toBeNull()
        ->and($forecast->isOptimistic())->toBeFalse();
});

// -- Access --------------------------------------------------------------------

test('a forecast only counts the deals the viewer can see', function () {
    $mine = reportUser(['reports.view', 'deals.view']);
    $pipeline = forecastPipeline();

    forecastDeal($mine, $pipeline, 'proposal', 1000, '2026-09-20');
    forecastDeal(reportAdmin(), $pipeline, 'proposal', 9000, '2026-09-21');
    wonDeal($mine, $pipeline, 400, '2026-09-05');
    wonDeal(reportAdmin(), $pipeline, 8000, '2026-09-06');

    $forecast = app(ForecastCalculator::class)->forecast($mine, ForecastPeriod::ahead(1))[0];

    // A forecast is of the deals its reader could open one by one.
    expect($forecast->weighted)->toBe(500.0)
        ->and($forecast->committed)->toBe(400.0);
});

test('a removed deal is not forecast', function () {
    $viewer = reportAdmin();
    $pipeline = forecastPipeline();

    forecastDeal($viewer, $pipeline, 'proposal', 1000, '2026-09-20')->delete();

    expect(app(ForecastCalculator::class)->forecast($viewer, ForecastPeriod::ahead(1))[0]->weighted)
        ->toBe(0.0);
});

// -- The screen ----------------------------------------------------------------

test('the forecast screen needs the deals permission', function () {
    Livewire::actingAs(reportUser(['reports.view']))
        ->test(SalesForecast::class)
        ->assertForbidden();

    Livewire::actingAs(reportUser(['reports.view', 'deals.view']))
        ->test(SalesForecast::class)
        ->assertOk();
});

test('the screen shows both forecasts and the history', function () {
    $viewer = reportAdmin();
    $pipeline = forecastPipeline();

    wonDeal($viewer, $pipeline, 600, '2026-08-10');
    forecastDeal($viewer, $pipeline, 'negotiation', 1000, '2026-09-20');

    Livewire::actingAs($viewer)
        ->test(SalesForecast::class)
        ->assertOk()
        ->assertSee('Weighted pipeline')
        ->assertSee('Best case')
        ->assertSee('What actually closed')
        ->assertSee('2026-08');
});

test('the totals across the months add up', function () {
    $viewer = reportAdmin();
    $pipeline = forecastPipeline();

    forecastDeal($viewer, $pipeline, 'negotiation', 1000, '2026-09-20');  // 800
    forecastDeal($viewer, $pipeline, 'proposal', 1000, '2026-10-20');     // 500
    wonDeal($viewer, $pipeline, 300, '2026-09-02');

    $totals = Livewire::actingAs($viewer)->test(SalesForecast::class)->instance()->totals();

    expect($totals['weighted'])->toBe(1300.0)
        ->and($totals['committed'])->toBe(300.0)
        ->and($totals['expected'])->toBe(1600.0)
        ->and($totals['best_case'])->toBe(2300.0);
});

test('how far ahead the forecast runs is clamped', function () {
    $screen = Livewire::actingAs(reportAdmin())->test(SalesForecast::class);

    $screen->set('months', '999');
    expect($screen->instance()->monthCount())->toBe(SalesForecast::MAX_MONTHS);

    // An expected close date two years out is a guess about a guess.
    $screen->set('months', '0');
    expect($screen->instance()->monthCount())->toBe(1);
});

test('the forecast page is reachable and is not shadowed by a report id', function () {
    $this->actingAs(reportAdmin())
        ->get(route('reports.forecast'))
        ->assertOk()
        ->assertSee('Sales forecast');
});
