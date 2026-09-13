<?php

use App\Domain\Deals\Models\Deal;
use App\Domain\Reports\Enums\ChartType;
use App\Domain\Reports\Models\DashboardWidget;
use App\Domain\Reports\Models\Report;
use App\Livewire\Reports\KpiDashboard;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-09-30 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

// -- The layout is per person ---------------------------------------------------

test('a dashboard shows only its own widgets', function () {
    $mine = reportAdmin();
    $theirs = reportAdmin();

    $report = Report::factory()->shared()->create(['name' => 'Deals by stage']);
    DashboardWidget::factory()->forUser($mine)->showing($report)->create();
    DashboardWidget::factory()->forUser($theirs)->showing($report)->create();

    $screen = Livewire::actingAs($mine)->test(KpiDashboard::class);

    expect($screen->instance()->widgets())->toHaveCount(1)
        ->and($screen->instance()->widgets()->first()->user_id)->toBe($mine->id);
});

test('adding a report puts it on the dashboard, once', function () {
    $viewer = reportAdmin();
    $report = Report::factory()->ownedBy($viewer)->create();

    Livewire::actingAs($viewer)
        ->test(KpiDashboard::class)
        ->set('addReportId', (string) $report->id)
        ->call('add')
        ->set('addReportId', (string) $report->id)
        ->call('add');

    // The unique index is what stops a double-click adding a duplicate nobody
    // notices until they remove one and the other stays.
    expect(DashboardWidget::query()->count())->toBe(1);
});

test('the order a drag reports is the order that is saved', function () {
    $viewer = reportAdmin();

    $first = DashboardWidget::factory()->forUser($viewer)->at(0)->create();
    $second = DashboardWidget::factory()->forUser($viewer)->at(1)->create();
    $third = DashboardWidget::factory()->forUser($viewer)->at(2)->create();

    Livewire::actingAs($viewer)
        ->test(KpiDashboard::class)
        ->call('reorder', [$third->id, $first->id, $second->id]);

    expect($third->fresh()->position)->toBe(0)
        ->and($first->fresh()->position)->toBe(1)
        ->and($second->fresh()->position)->toBe(2);
});

test('the saved order survives a fresh page', function () {
    $viewer = reportAdmin();

    $first = DashboardWidget::factory()->forUser($viewer)->at(0)->create();
    $second = DashboardWidget::factory()->forUser($viewer)->at(1)->create();

    Livewire::actingAs($viewer)
        ->test(KpiDashboard::class)
        ->call('reorder', [$second->id, $first->id]);

    // A new component instance, as a page load would give.
    $reopened = Livewire::actingAs($viewer)->test(KpiDashboard::class);

    expect($reopened->instance()->widgets()->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('a drag cannot move somebody else widget', function () {
    $mine = reportAdmin();
    $theirs = reportAdmin();

    $ours = DashboardWidget::factory()->forUser($mine)->at(0)->create();
    $elsewhere = DashboardWidget::factory()->forUser($theirs)->at(0)->create();

    Livewire::actingAs($mine)
        ->test(KpiDashboard::class)
        ->call('reorder', [$elsewhere->id, $ours->id]);

    // The order arrives from the browser; only this person's widgets are moved.
    expect($elsewhere->fresh()->position)->toBe(0)
        ->and($ours->fresh()->position)->toBe(0);
});

test('a widget the drag did not mention keeps its place rather than jumping to the front', function () {
    $viewer = reportAdmin();

    $first = DashboardWidget::factory()->forUser($viewer)->at(0)->create();
    $second = DashboardWidget::factory()->forUser($viewer)->at(1)->create();
    $third = DashboardWidget::factory()->forUser($viewer)->at(2)->create();

    // A drag that arrived mid-render, naming only two.
    Livewire::actingAs($viewer)
        ->test(KpiDashboard::class)
        ->call('reorder', [$second->id, $first->id]);

    expect($second->fresh()->position)->toBe(0)
        ->and($first->fresh()->position)->toBe(1)
        ->and($third->fresh()->position)->toBe(2);
});

// -- Configuring ----------------------------------------------------------------

test('a widget can be made wider, and the width is clamped', function () {
    $viewer = reportAdmin();
    $widget = DashboardWidget::factory()->forUser($viewer)->create();

    $screen = Livewire::actingAs($viewer)->test(KpiDashboard::class);

    $screen->call('resize', $widget->id, 2);
    expect($widget->fresh()->width)->toBe(2);

    // A width from a hand-edited row must not break the layout.
    $screen->call('resize', $widget->id, 99);
    expect($widget->fresh()->width)->toBe(DashboardWidget::MAX_WIDTH)
        ->and($widget->fresh()->span())->toBe(3);
});

test('a widget can draw the report a different way, or defer to it', function () {
    $viewer = reportAdmin();
    $report = Report::factory()->ownedBy($viewer)->create(['chart_type' => 'pie']);
    $widget = DashboardWidget::factory()->forUser($viewer)->showing($report)->create();

    $screen = Livewire::actingAs($viewer)->test(KpiDashboard::class);

    $screen->call('setChart', $widget->id, 'bar');
    expect($widget->fresh()->chart())->toBe(ChartType::Bar);

    // An empty choice means "however the report says", which is not the same
    // as a table — the report here is a pie.
    $screen->call('setChart', $widget->id, '');
    expect($widget->fresh()->chart_type)->toBeNull()
        ->and($widget->fresh()->chart())->toBe(ChartType::Pie);
});

test('a widget with no title of its own follows the report name', function () {
    $viewer = reportAdmin();
    $report = Report::factory()->ownedBy($viewer)->create(['name' => 'Deals by stage']);
    $widget = DashboardWidget::factory()->forUser($viewer)->showing($report)->create(['title' => null]);

    expect($widget->heading())->toBe('Deals by stage');

    $report->forceFill(['name' => 'Pipeline'])->save();

    expect($widget->fresh()->heading())->toBe('Pipeline');
});

test('removing a widget leaves the report alone', function () {
    $viewer = reportAdmin();
    $report = Report::factory()->ownedBy($viewer)->create();
    $widget = DashboardWidget::factory()->forUser($viewer)->showing($report)->create();

    Livewire::actingAs($viewer)
        ->test(KpiDashboard::class)
        ->call('remove', $widget->id);

    expect(DashboardWidget::query()->whereKey($widget->id)->exists())->toBeFalse()
        ->and(Report::query()->whereKey($report->id)->exists())->toBeTrue();
});

test('somebody else widget cannot be removed or resized by guessing its id', function () {
    $mine = reportAdmin();
    $theirs = reportAdmin();
    $elsewhere = DashboardWidget::factory()->forUser($theirs)->create(['width' => 1]);

    Livewire::actingAs($mine)
        ->test(KpiDashboard::class)
        ->call('remove', $elsewhere->id)
        ->call('resize', $elsewhere->id, 3);

    expect(DashboardWidget::query()->whereKey($elsewhere->id)->exists())->toBeTrue()
        ->and($elsewhere->fresh()->width)->toBe(1);
});

test('removing a report takes its widgets with it', function () {
    $viewer = reportAdmin();
    $report = Report::factory()->ownedBy($viewer)->create();
    DashboardWidget::factory()->forUser($viewer)->showing($report)->create();

    // forceDelete, because the widget's foreign key cascades on a real delete —
    // a soft-deleted report leaves the layout alone, which is also right.
    $report->forceDelete();

    expect(DashboardWidget::query()->count())->toBe(0);
});

// -- What can be added ----------------------------------------------------------

test('only reports the person can see and run are offered', function () {
    $viewer = reportUser(['reports.view', 'deals.view']);

    $visible = Report::factory()->shared()->asking('deals', ['measures' => ['count']])->create(['name' => 'Deals']);
    Report::factory()->create(['name' => 'Somebody private']);
    Report::factory()->shared()->asking('tickets', ['measures' => ['count']])->create(['name' => 'Tickets']);

    $offered = Livewire::actingAs($viewer)->test(KpiDashboard::class)->instance()->addableReports();

    // A widget that could never draw anything is not worth offering.
    expect($offered)->toBe([$visible->id => 'Deals']);
});

test('a report already on the dashboard is not offered again', function () {
    $viewer = reportAdmin();
    $report = Report::factory()->ownedBy($viewer)->create();
    DashboardWidget::factory()->forUser($viewer)->showing($report)->create();

    expect(Livewire::actingAs($viewer)->test(KpiDashboard::class)->instance()->addableReports())->toBe([]);
});

test('a private report cannot be added by posting its id', function () {
    $viewer = reportAdmin();
    $theirs = Report::factory()->create();

    Livewire::actingAs($viewer)
        ->test(KpiDashboard::class)
        ->set('addReportId', (string) $theirs->id)
        ->call('add');

    expect(DashboardWidget::query()->count())->toBe(0);
});

test('a dashboard stops at its limit', function () {
    $viewer = reportAdmin();

    DashboardWidget::factory()->forUser($viewer)->count(KpiDashboard::MAX_WIDGETS)->create();

    $report = Report::factory()->ownedBy($viewer)->create();

    $screen = Livewire::actingAs($viewer)->test(KpiDashboard::class);

    expect($screen->instance()->isFull())->toBeTrue();

    $screen->set('addReportId', (string) $report->id)->call('add');

    // Each widget is a query, so a dashboard is as expensive as its length.
    expect(DashboardWidget::query()->count())->toBe(KpiDashboard::MAX_WIDGETS);
});

// -- Running --------------------------------------------------------------------

test('a widget is answered from what the viewer can see', function () {
    $mine = reportUser(['reports.view', 'deals.view']);

    Deal::factory()->ownedBy($mine)->create(['value' => 100]);
    Deal::factory()->create(['value' => 9000]);

    $report = Report::factory()->shared()->asking('deals', ['measures' => ['count', 'value']])->create();
    $widget = DashboardWidget::factory()->forUser($mine)->showing($report)->create();

    $result = Livewire::actingAs($mine)->test(KpiDashboard::class)->instance()->resultFor($widget->fresh());

    expect($result->totals['value'])->toBe(100.0);
});

test('the dashboard renders its widgets', function () {
    $viewer = reportAdmin();

    Deal::factory()->ownedBy($viewer)->create(['stage' => 'qualification', 'value' => 100]);

    $report = Report::factory()->ownedBy($viewer)->create(['name' => 'Deals by stage']);
    DashboardWidget::factory()->forUser($viewer)->showing($report)->create();

    Livewire::actingAs($viewer)
        ->test(KpiDashboard::class)
        ->assertOk()
        ->assertSee('Deals by stage');
});

test('an empty dashboard explains itself', function () {
    Livewire::actingAs(reportAdmin())
        ->test(KpiDashboard::class)
        ->assertSee('Nothing here yet')
        ->assertSee('Press Arrange');
});

test('the dashboard needs the reports permission', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(KpiDashboard::class)
        ->assertForbidden();
});

test('the dashboard page is reachable', function () {
    $this->actingAs(reportAdmin())->get(route('reports.dashboard'))->assertOk();
});

test('the dashboard route is not shadowed by a report id', function () {
    $this->actingAs(reportAdmin())
        ->get(route('reports.dashboard'))
        ->assertOk()
        ->assertSee('KPI dashboard');
});
