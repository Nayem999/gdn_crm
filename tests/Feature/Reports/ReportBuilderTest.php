<?php

use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Reports\Models\Report;
use App\Domain\Reports\ReportRunner;
use App\Domain\Shared\Enums\FilterOperator;
use App\Domain\Shared\Filters\FilterGroup;
use App\Livewire\Reports\ReportBuilder;
use App\Livewire\Reports\ReportShow;
use App\Livewire\Reports\ReportsIndex;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-09-30 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

// -- The builder produces what the runner would ---------------------------------

test('the preview is the same result the saved report gives', function () {
    $viewer = reportAdmin();

    Deal::factory()->ownedBy($viewer)->create(['value' => 1000, 'stage' => 'qualification']);
    Deal::factory()->ownedBy($viewer)->create(['value' => 2500, 'stage' => 'qualification']);
    Deal::factory()->ownedBy($viewer)->create(['value' => 400, 'stage' => 'proposal']);

    $screen = Livewire::actingAs($viewer)
        ->test(ReportBuilder::class)
        ->set('source', 'deals')
        ->call('addDimension', 'stage')
        ->call('addMeasure', 'value')
        ->call('addMeasure', 'count');

    $preview = $screen->instance()->result();

    // The builder runs through the same ReportRunner, so what somebody sees
    // while building is what a saved report will produce. A second query path
    // would be a second thing to keep in step.
    $direct = app(ReportRunner::class)->run($screen->instance()->definition(), $viewer);

    expect($preview->toArray())->toBe($direct->toArray())
        ->and($preview->totals['value'])->toBe(3900.0)
        ->and($preview->totals['count'])->toBe(3);
});

test('what the builder saves is what the report then runs', function () {
    $viewer = reportAdmin();

    Lead::factory()->count(2)->ownedBy($viewer)->create(['status' => LeadStatus::New->value]);
    Lead::factory()->ownedBy($viewer)->create(['status' => LeadStatus::Qualified->value]);

    Livewire::actingAs($viewer)
        ->test(ReportBuilder::class)
        ->set('source', 'leads')
        ->set('name', 'Leads by status')
        ->call('addDimension', 'status')
        ->call('addMeasure', 'count')
        ->call('save')
        ->assertHasNoErrors();

    $report = Report::query()->firstOrFail();

    $result = app(ReportRunner::class)->run($report->definition(), $viewer);

    expect($report->source)->toBe('leads')
        ->and($result->totals['count'])->toBe(3)
        ->and($result->rowCount())->toBe(2);
});

// -- Building ------------------------------------------------------------------

test('a field is added once, however many times it is dropped in', function () {
    $screen = Livewire::actingAs(reportAdmin())
        ->test(ReportBuilder::class)
        ->set('source', 'deals')
        ->call('addDimension', 'stage')
        ->call('addDimension', 'stage');

    expect($screen->get('dimensions'))->toBe(['stage']);
});

test('a field the source does not offer is not added', function () {
    $screen = Livewire::actingAs(reportAdmin())
        ->test(ReportBuilder::class)
        ->set('source', 'deals')
        ->call('addDimension', 'deals.owner_id')
        ->call('addMeasure', '(select 1)');

    expect($screen->get('dimensions'))->toBe([])
        ->and($screen->get('measures'))->toBe([]);
});

test('the tray offers only what is not already in the report', function () {
    $screen = Livewire::actingAs(reportAdmin())
        ->test(ReportBuilder::class)
        ->set('source', 'deals')
        ->call('addDimension', 'stage');

    expect($screen->instance()->availableDimensions())->not->toHaveKey('stage')
        ->and($screen->instance()->chosenDimensions())->toHaveKey('stage');
});

test('dragging reorders the grouping', function () {
    $screen = Livewire::actingAs(reportAdmin())
        ->test(ReportBuilder::class)
        ->set('source', 'deals')
        ->call('addDimension', 'stage')
        ->call('addDimension', 'owner')
        ->call('reorderDimensions', ['owner', 'stage']);

    // The order is the column order and the outermost grouping, so a drag is a
    // real change to the question rather than a cosmetic one.
    expect($screen->get('dimensions'))->toBe(['owner', 'stage']);
});

test('a drag cannot add a field that is not in the report', function () {
    $screen = Livewire::actingAs(reportAdmin())
        ->test(ReportBuilder::class)
        ->set('source', 'deals')
        ->call('addDimension', 'stage')
        ->call('reorderDimensions', ['close_reason', 'stage', 'deals.owner_id']);

    // The order arrives from the browser; a key not already chosen has no
    // business being added by reordering it.
    expect($screen->get('dimensions'))->toBe(['stage']);
});

test('a field the drag did not mention keeps its place rather than vanishing', function () {
    $screen = Livewire::actingAs(reportAdmin())
        ->test(ReportBuilder::class)
        ->set('source', 'deals')
        ->call('addDimension', 'stage')
        ->call('addDimension', 'owner')
        // A drag that arrived mid-render, naming only one of them.
        ->call('reorderMeasures', [])
        ->call('reorderDimensions', ['owner']);

    expect($screen->get('dimensions'))->toBe(['owner', 'stage']);
});

test('removing a field also clears it as the sort', function () {
    $screen = Livewire::actingAs(reportAdmin())
        ->test(ReportBuilder::class)
        ->set('source', 'deals')
        ->call('addMeasure', 'value')
        ->call('sortByColumn', 'value')
        ->call('removeMeasure', 'value');

    expect($screen->get('sortBy'))->toBe('');
});

test('changing the source clears the fields chosen for the old one', function () {
    $screen = Livewire::actingAs(reportAdmin())
        ->test(ReportBuilder::class)
        ->set('source', 'deals')
        ->call('addDimension', 'stage')
        ->call('addMeasure', 'value')
        ->set('source', 'leads');

    // A deal's stage means nothing on a lead, and keeping it would produce a
    // column that never fills.
    expect($screen->get('dimensions'))->toBe([])
        ->and($screen->get('measures'))->toBe([]);
});

test('sorting a column twice turns it round', function () {
    $screen = Livewire::actingAs(reportAdmin())
        ->test(ReportBuilder::class)
        ->set('source', 'deals')
        ->call('addMeasure', 'value')
        ->call('sortByColumn', 'value')
        ->assertSet('sortDirection', 'desc')
        ->call('sortByColumn', 'value')
        ->assertSet('sortDirection', 'asc');

    expect($screen->get('sortBy'))->toBe('value');
});

test('a sort on something not in the report is ignored', function () {
    $screen = Livewire::actingAs(reportAdmin())
        ->test(ReportBuilder::class)
        ->set('source', 'deals')
        ->call('addMeasure', 'value')
        ->call('sortByColumn', 'deals.id');

    expect($screen->get('sortBy'))->toBe('');
});

test('the sort chosen in the builder is the order the rows come back in', function () {
    $viewer = reportAdmin();

    Deal::factory()->ownedBy($viewer)->create(['value' => 100, 'stage' => 'qualification']);
    Deal::factory()->ownedBy($viewer)->create(['value' => 900, 'stage' => 'proposal']);

    $screen = Livewire::actingAs($viewer)
        ->test(ReportBuilder::class)
        ->set('source', 'deals')
        ->call('addDimension', 'stage')
        ->call('addMeasure', 'value')
        ->call('sortByColumn', 'value')
        ->call('sortByColumn', 'value');

    // Ascending after the second press.
    expect($screen->instance()->result()->series('value'))->toBe([100.0, 900.0]);
});

test('a filter in the builder narrows the preview', function () {
    $viewer = reportAdmin();

    Lead::factory()->count(2)->ownedBy($viewer)->create(['status' => LeadStatus::New->value]);
    Lead::factory()->ownedBy($viewer)->create(['status' => LeadStatus::Qualified->value]);

    $screen = Livewire::actingAs($viewer)
        ->test(ReportBuilder::class)
        ->set('source', 'leads')
        ->call('addMeasure', 'count')
        ->set('filters', [
            'match' => FilterGroup::MATCH_ALL,
            'conditions' => [[
                'field' => 'status',
                'operator' => FilterOperator::Equals->value,
                'value' => LeadStatus::New->value,
                'second_value' => null,
                'selected' => [],
            ]],
            'groups' => [],
        ]);

    expect($screen->instance()->result()->totals['count'])->toBe(2);
});

// -- Saving --------------------------------------------------------------------

test('a report with no measure is refused', function () {
    Livewire::actingAs(reportAdmin())
        ->test(ReportBuilder::class)
        ->set('source', 'deals')
        ->set('name', 'Nothing much')
        ->call('addDimension', 'stage')
        ->call('save')
        ->assertHasErrors(['measures']);

    expect(Report::query()->count())->toBe(0);
});

test('a report needs a name', function () {
    Livewire::actingAs(reportAdmin())
        ->test(ReportBuilder::class)
        ->set('source', 'deals')
        ->set('name', '')
        ->call('addMeasure', 'count')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

test('what is stored is keys, not SQL', function () {
    $viewer = reportAdmin();

    Livewire::actingAs($viewer)
        ->test(ReportBuilder::class)
        ->set('source', 'deals')
        ->set('name', 'Deals by stage')
        ->call('addDimension', 'stage')
        ->call('addMeasure', 'value')
        ->call('save');

    $stored = Report::query()->firstOrFail()->definition;

    // A row edited by hand still cannot make the runner select something it
    // was never offered, because the engine resolves keys and drops the rest.
    expect($stored['dimensions'])->toBe(['stage'])
        ->and($stored['measures'])->toBe(['value'])
        ->and(json_encode($stored))->not->toContain('SELECT')
        ->and(json_encode($stored))->not->toContain('deals.');
});

test('somebody who may not share cannot save a shared report', function () {
    $viewer = reportUser(['reports.view', 'reports.create', 'deals.view']);

    Livewire::actingAs($viewer)
        ->test(ReportBuilder::class)
        ->set('source', 'deals')
        ->set('name', 'Mine alone')
        ->set('isShared', true)
        ->call('addMeasure', 'count')
        ->call('save')
        ->assertHasErrors(['isShared']);

    expect(Report::query()->count())->toBe(0);
});

test('building a report needs the create permission', function () {
    Livewire::actingAs(reportUser(['reports.view']))
        ->test(ReportBuilder::class)
        ->assertForbidden();
});

test('the builder opens on a source the person may actually report on', function () {
    $limited = reportUser(['reports.view', 'reports.create', 'tickets.view']);

    $screen = Livewire::actingAs($limited)->test(ReportBuilder::class);

    expect($screen->get('source'))->toBe('tickets');
});

// -- The list ------------------------------------------------------------------

test('the list shows your own reports and everybody shared ones', function () {
    $mine = reportAdmin();
    $theirs = reportAdmin();

    Report::factory()->ownedBy($mine)->create(['name' => 'Mine private']);
    Report::factory()->ownedBy($theirs)->create(['name' => 'Theirs private']);
    Report::factory()->ownedBy($theirs)->shared()->create(['name' => 'Theirs shared']);

    Livewire::actingAs($mine)
        ->test(ReportsIndex::class)
        ->assertSee('Mine private')
        ->assertSee('Theirs shared')
        // "I am still working on this" is a real state.
        ->assertDontSee('Theirs private');
});

test('a private report cannot be opened by guessing its id', function () {
    $theirs = Report::factory()->create();

    Livewire::actingAs(reportAdmin())
        ->test(ReportShow::class, ['report' => $theirs])
        ->assertForbidden();
});

test('a standard report cannot be removed', function () {
    $viewer = reportAdmin();
    $standard = Report::factory()->standard('deals-by-stage')->create(['name' => 'Deals by stage']);

    Livewire::actingAs($viewer)
        ->test(ReportsIndex::class)
        ->call('delete', $standard->id);

    // Half the application links to these.
    expect(Report::query()->whereKey($standard->id)->exists())->toBeTrue()
        ->and($viewer->can('delete', $standard))->toBeFalse();
});

test('duplicating gives you your own private copy', function () {
    $viewer = reportAdmin();
    $standard = Report::factory()->standard('deals-by-stage')->create(['name' => 'Deals by stage']);

    Livewire::actingAs($viewer)
        ->test(ReportsIndex::class)
        ->call('duplicate', $standard->id);

    $copy = Report::query()->where('name', 'Deals by stage (copy)')->firstOrFail();

    expect($copy->owner_id)->toBe($viewer->id)
        ->and($copy->is_shared)->toBeFalse()
        // A duplicate of a built-in is somebody's own report, and would
        // otherwise be undeletable.
        ->and($copy->is_standard)->toBeFalse()
        ->and($copy->slug)->toBeNull();
});

test('the list can be narrowed to mine, shared or built in', function () {
    $mine = reportAdmin();

    Report::factory()->ownedBy($mine)->create(['name' => 'Mine private']);
    Report::factory()->shared()->create(['name' => 'Somebody shared']);
    Report::factory()->standard('built-in')->create(['name' => 'A built-in one']);

    Livewire::actingAs($mine)
        ->test(ReportsIndex::class)
        ->call('setScope', 'mine')
        ->assertSee('Mine private')
        ->assertDontSee('Somebody shared')
        ->call('setScope', 'mine')
        ->call('setScope', 'standard')
        ->assertSee('A built-in one')
        ->assertDontSee('Mine private');
});

// -- Running a saved report -----------------------------------------------------

test('a saved report is run as the viewer, not as its author', function () {
    $author = reportAdmin();
    $agent = reportUser(['reports.view', 'deals.view']);

    Deal::factory()->ownedBy($agent)->create(['value' => 100]);
    Deal::factory()->ownedBy($author)->create(['value' => 5000]);

    $report = Report::factory()->ownedBy($author)->shared()->asking('deals', [
        'measures' => ['count', 'value'],
    ])->create();

    $asAgent = Livewire::actingAs($agent)->test(ReportShow::class, ['report' => $report])->instance()->result();
    $asAuthor = Livewire::actingAs($author)->test(ReportShow::class, ['report' => $report])->instance()->result();

    // Two people opening the same shared report see different numbers because
    // they can see different records. That is correct, not a bug.
    expect($asAgent->totals['value'])->toBe(100.0)
        ->and($asAuthor->totals['value'])->toBe(5100.0);
});

test('a shared report about a module the reader cannot see is listed and refuses to run', function () {
    $agent = reportUser(['reports.view']);

    $report = Report::factory()->shared()->asking('deals', ['measures' => ['count']])->create(['name' => 'Deal totals']);

    // Listed — a report that appeared for one colleague and not another would
    // look like a fault.
    Livewire::actingAs($agent)->test(ReportsIndex::class)->assertSee('Deal totals');

    $result = Livewire::actingAs($agent)->test(ReportShow::class, ['report' => $report])->instance()->result();

    expect($result->refused)->toBeTrue()
        ->and($report->runnableBy($agent))->toBeFalse();
});

test('opening a report stamps when it was last run', function () {
    $viewer = reportAdmin();
    $report = Report::factory()->ownedBy($viewer)->create();

    expect($report->last_run_at)->toBeNull();

    Livewire::actingAs($viewer)->test(ReportShow::class, ['report' => $report]);

    expect($report->fresh()->last_run_at)->not->toBeNull();
});

test('the stored source wins over anything inside the definition JSON', function () {
    $viewer = reportAdmin();

    $report = Report::factory()->ownedBy($viewer)->create([
        'source' => 'leads',
        'definition' => ['source' => 'deals', 'measures' => ['count']],
    ]);

    // Two sources of truth for the same fact is how a report ends up filed
    // under one module and run against another.
    expect($report->definition()->source)->toBe('leads');
});

// -- Routes --------------------------------------------------------------------

test('the report pages are reachable', function () {
    $viewer = reportAdmin();
    $report = Report::factory()->ownedBy($viewer)->create();

    $this->actingAs($viewer)->get(route('reports.index'))->assertOk();
    $this->actingAs($viewer)->get(route('reports.create'))->assertOk();
    $this->actingAs($viewer)->get(route('reports.show', $report))->assertOk();
    $this->actingAs($viewer)->get(route('reports.edit', $report))->assertOk();
});

test('the new-report page is not shadowed by a report id', function () {
    $this->actingAs(reportAdmin())
        ->get(route('reports.create'))
        ->assertOk()
        ->assertSee('Build a report');
});
