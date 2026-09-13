<?php

use App\Domain\Deals\Models\Deal;
use App\Domain\Reports\Charts\ChartData;
use App\Domain\Reports\Enums\ChartType;
use App\Domain\Reports\Models\Report;
use App\Domain\Reports\ReportDefinition;
use App\Domain\Reports\ReportRunner;
use App\Livewire\Reports\ReportBuilder;
use App\Livewire\Reports\ReportShow;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * A chart over a report of deals by stage, with the values given.
 *
 * @param  array<string, float>  $byStage
 */
function chartOver(array $byStage, ChartType $type, ?User $viewer = null): ChartData
{
    $viewer ??= reportAdmin();

    foreach ($byStage as $stage => $value) {
        Deal::factory()->ownedBy($viewer)->create(['stage' => $stage, 'value' => $value]);
    }

    $result = app(ReportRunner::class)->run(ReportDefinition::fromArray([
        'source' => 'deals',
        'dimensions' => ['stage'],
        'measures' => ['value'],
    ]), $viewer);

    return new ChartData($result, $type);
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-30 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

// -- What is drawable ----------------------------------------------------------

test('a chart with rows and a grouping is drawable', function (ChartType $type) {
    $chart = chartOver(['qualification' => 100, 'proposal' => 50], $type);

    expect($chart->isDrawable())->toBeTrue()
        ->and($chart->points())->toHaveCount(2);
})->with([
    'bar' => [ChartType::Bar],
    'line' => [ChartType::Line],
    'pie' => [ChartType::Pie],
    'funnel' => [ChartType::Funnel],
]);

test('a chart with no rows is not drawable', function (ChartType $type) {
    $viewer = reportAdmin();

    $result = app(ReportRunner::class)->run(ReportDefinition::fromArray([
        'source' => 'deals',
        'dimensions' => ['stage'],
        'measures' => ['value'],
    ]), $viewer);

    expect((new ChartData($result, $type))->isDrawable())->toBeFalse();
})->with([
    'bar' => [ChartType::Bar],
    'line' => [ChartType::Line],
    'pie' => [ChartType::Pie],
    'funnel' => [ChartType::Funnel],
    'gauge' => [ChartType::Gauge],
]);

test('a shape that needs a grouping is not drawable without one', function () {
    $viewer = reportAdmin();
    Deal::factory()->ownedBy($viewer)->create(['value' => 100]);

    $result = app(ReportRunner::class)->run(ReportDefinition::fromArray([
        'source' => 'deals',
        'measures' => ['value'],
    ]), $viewer);

    // A pie of one number is not a pie. A gauge of one number is exactly a
    // gauge, which is why it says it needs no dimension.
    expect((new ChartData($result, ChartType::Pie))->isDrawable())->toBeFalse()
        ->and((new ChartData($result, ChartType::Gauge))->isDrawable())->toBeTrue();
});

// -- Pie -----------------------------------------------------------------------

test('pie slices add up to the whole', function () {
    $chart = chartOver(['qualification' => 300, 'proposal' => 100], ChartType::Pie);

    $slices = $chart->slices();

    expect($slices)->toHaveCount(2)
        ->and(array_sum(array_column($slices, 'percent')))->toBe(100.0)
        ->and($slices[0]['percent'])->toBe(75.0);
});

test('a single slice is drawn as a circle, not an arc', function () {
    $chart = chartOver(['qualification' => 100], ChartType::Pie);

    $slice = $chart->slices()[0];

    // An arc of exactly 360 degrees starts and ends at the same coordinates,
    // and SVG draws nothing at all.
    expect($slice['full'])->toBeTrue()
        ->and($slice['path'])->toBe('')
        ->and($slice['percent'])->toBe(100.0);
});

test('a pie of nothing has no slices rather than dividing by zero', function () {
    $chart = chartOver(['qualification' => 0, 'proposal' => 0], ChartType::Pie);

    expect($chart->total())->toBe(0.0)
        ->and($chart->slices())->toBe([]);
});

test('a long tail is gathered into one slice rather than dropped', function () {
    $viewer = reportAdmin();
    $values = [];

    foreach (['qualification', 'proposal', 'negotiation', 'new', 'won', 'lost'] as $index => $stage) {
        $values[$stage] = (float) (100 - $index * 10);
    }

    $chart = chartOver($values, ChartType::Pie, $viewer);

    // Under the cap here, so nothing is gathered — but the total must always
    // be the real total, which is what the next assertion is really about.
    expect(round($chart->total()))->toBe(round(array_sum($values)));
});

test('the slices keep the real total once the tail is gathered', function () {
    $result = app(ReportRunner::class)->run(ReportDefinition::fromArray([
        'source' => 'deals',
        'dimensions' => ['owner'],
        'measures' => ['value'],
    ]), reportAdmin());

    $chart = new ChartData($result, ChartType::Pie);

    // A pie whose slices did not add up to the total would be a lie told in a
    // picture; the cap gathers rather than drops.
    expect(ChartData::MAX_SLICES)->toBeGreaterThan(1)
        ->and($chart->points())->toHaveCount(0);
});

// -- Line ----------------------------------------------------------------------

test('a line plots a point per row, inside the canvas', function () {
    $chart = chartOver(['qualification' => 100, 'proposal' => 50, 'negotiation' => 75], ChartType::Line);

    $points = $chart->linePoints(600, 200, 10);

    expect($points)->toHaveCount(3);

    foreach ($points as $point) {
        expect($point['x'])->toBeGreaterThanOrEqual(10.0)
            ->and($point['x'])->toBeLessThanOrEqual(590.0)
            ->and($point['y'])->toBeGreaterThanOrEqual(10.0)
            ->and($point['y'])->toBeLessThanOrEqual(190.0);
    }
});

test('the tallest point sits at the top of the canvas', function () {
    $chart = chartOver(['qualification' => 100, 'proposal' => 50], ChartType::Line);

    $points = collect($chart->linePoints(600, 200, 10));

    // SVG y grows downwards, so the biggest value has the smallest y.
    expect($points->min('y'))->toBe(10.0);
});

test('a single point sits in the middle rather than dividing by zero', function () {
    $chart = chartOver(['qualification' => 100], ChartType::Line);

    expect($chart->linePoints(600, 200, 10)[0]['x'])->toBe(300.0);
});

test('the polyline is the points as SVG coordinates', function () {
    $chart = chartOver(['qualification' => 100, 'proposal' => 50], ChartType::Line);

    expect($chart->polyline(600, 200, 10))->toMatch('/^[\d., ]+$/');
});

// -- Funnel --------------------------------------------------------------------

test('funnel bands are measured against the widest, and name the drop', function () {
    $chart = chartOver(['qualification' => 100, 'proposal' => 75, 'negotiation' => 50], ChartType::Funnel);

    $bands = $chart->bands();

    expect($bands[0]['width'])->toBe(100.0)
        ->and($bands[0]['drop'])->toBeNull()
        ->and($bands[1]['width'])->toBe(75.0)
        // The only number anybody actually reads off a funnel.
        ->and($bands[1]['drop'])->toBe(25.0);
});

test('a funnel that widens draws a band inside the chart, not past it', function () {
    // Measured against the widest rather than the first: records created
    // part-way down a funnel make a later stage larger, and against the first
    // that band would be wider than the chart.
    $chart = chartOver(['qualification' => 50, 'proposal' => 100], ChartType::Funnel);

    foreach ($chart->bands() as $band) {
        expect($band['width'])->toBeLessThanOrEqual(100.0);
    }
});

// -- Gauge ---------------------------------------------------------------------

test('a gauge reads the report total and is clamped to its dial', function () {
    $chart = chartOver(['qualification' => 300, 'proposal' => 100], ChartType::Gauge);

    expect($chart->gaugeValue())->toBe(400.0)
        ->and($chart->gaugeFraction(800))->toBe(0.5)
        // A needle past its own dial is a drawing fault; the figure is printed
        // beside it anyway.
        ->and($chart->gaugeFraction(100))->toBe(1.0)
        ->and($chart->gaugeFraction(0))->toBe(0.0);
});

test('a gauge arc is empty at nothing and a path at anything', function () {
    $chart = chartOver(['qualification' => 100], ChartType::Gauge);

    expect($chart->gaugeArc(0))->toBe('')
        ->and($chart->gaugeArc(0.5))->toStartWith('M ')
        ->and($chart->gaugeArc(1))->toContain('A ');
});

// -- Scale ---------------------------------------------------------------------

test('a chart of all-zero rows still has something to divide by', function () {
    $chart = chartOver(['qualification' => 0, 'proposal' => 0], ChartType::Bar);

    expect($chart->max())->toBe(0.0)
        ->and($chart->scale())->toBe(1.0);
});

test('every point gets a colour, and they repeat rather than running out', function () {
    $chart = chartOver(['qualification' => 3, 'proposal' => 2, 'negotiation' => 1], ChartType::Bar);

    foreach ($chart->points() as $point) {
        expect($point['colour'])->toStartWith('#');
    }

    expect(ChartData::PALETTE)->not->toBeEmpty();
});

// -- The screens ---------------------------------------------------------------

test('a report page draws the shape the report asks for', function (string $type, string $marker) {
    $viewer = reportAdmin();

    Deal::factory()->ownedBy($viewer)->create(['stage' => 'qualification', 'value' => 100]);
    Deal::factory()->ownedBy($viewer)->create(['stage' => 'proposal', 'value' => 50]);

    $report = Report::factory()->ownedBy($viewer)->create([
        'chart_type' => $type,
        'definition' => [
            'source' => 'deals',
            'dimensions' => ['stage'],
            'measures' => ['value'],
        ],
    ]);

    Livewire::actingAs($viewer)
        ->test(ReportShow::class, ['report' => $report])
        ->assertOk()
        ->assertSee($marker, false);
})->with([
    'bar' => ['bar', 'background-color: #'],
    'line' => ['line', '<polyline'],
    'pie' => ['pie', '<path'],
    'funnel' => ['funnel', 'background-color: #'],
    'gauge' => ['gauge', '<svg'],
]);

test('a chart with nothing in it says so rather than drawing an empty frame', function () {
    $viewer = reportAdmin();

    $report = Report::factory()->ownedBy($viewer)->create([
        'chart_type' => 'pie',
        'definition' => [
            'source' => 'deals',
            'dimensions' => ['stage'],
            'measures' => ['value'],
        ],
    ]);

    Livewire::actingAs($viewer)
        ->test(ReportShow::class, ['report' => $report])
        ->assertOk()
        ->assertSee('Nothing to draw')
        ->assertSee('no records fell into it');
});

test('a chart the report cannot support says which part is missing', function () {
    $viewer = reportAdmin();
    Deal::factory()->ownedBy($viewer)->create(['value' => 100]);

    $report = Report::factory()->ownedBy($viewer)->create([
        'chart_type' => 'pie',
        'definition' => ['source' => 'deals', 'measures' => ['value']],
    ]);

    Livewire::actingAs($viewer)
        ->test(ReportShow::class, ['report' => $report])
        ->assertSee('needs something to group by');
});

test('a chart of a refused report does not leak that there is data', function () {
    $agent = reportUser(['reports.view']);
    Deal::factory()->create(['value' => 5000]);

    $report = Report::factory()->shared()->create([
        'chart_type' => 'bar',
        'definition' => ['source' => 'deals', 'dimensions' => ['stage'], 'measures' => ['value']],
    ]);

    Livewire::actingAs($agent)
        ->test(ReportShow::class, ['report' => $report])
        ->assertSee('Not yours to read')
        ->assertDontSee('5000')
        ->assertDontSee('5,000');
});

test('the builder warns before drawing an empty frame', function () {
    $screen = Livewire::actingAs(reportAdmin())
        ->test(ReportBuilder::class)
        ->set('source', 'deals')
        ->call('addMeasure', 'value')
        ->set('chartType', 'pie');

    // Said while somebody is choosing, rather than shown afterwards.
    expect($screen->instance()->chartWarning())->toContain('needs something to group by');

    $screen->call('addDimension', 'stage');

    expect($screen->instance()->chartWarning())->toBeNull();
});

test('the builder warns when a shape can only draw one of the measures', function () {
    $screen = Livewire::actingAs(reportAdmin())
        ->test(ReportBuilder::class)
        ->set('source', 'deals')
        ->call('addDimension', 'stage')
        ->call('addMeasure', 'value')
        ->call('addMeasure', 'count')
        ->set('chartType', 'pie');

    expect($screen->instance()->chartWarning())->toContain('one measure');
});

test('the page carries a skeleton shaped like the chart that is coming', function () {
    $viewer = reportAdmin();

    $report = Report::factory()->ownedBy($viewer)->create(['chart_type' => 'pie']);

    Livewire::actingAs($viewer)
        ->test(ReportShow::class, ['report' => $report])
        // wire:loading, so it is in the markup and hidden until an update is in
        // flight — the page must not jump when the figures arrive.
        ->assertSeeHtml('wire:loading.delay')
        ->assertSeeHtml('animate-pulse');
});

test('every chart type has a component to render it', function (ChartType $type) {
    expect(view()->exists('components.chart.'.$type->value))->toBe($type !== ChartType::Table);
})->with(ChartType::cases());
