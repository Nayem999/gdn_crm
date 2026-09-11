<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Accounts\Models\Account;
use App\Domain\Activities\Models\Activity;
use App\Domain\Company\Models\Company;
use App\Domain\Dashboard\DashboardFeed;
use App\Domain\Dashboard\DashboardKpis;
use App\Domain\Dashboard\DashboardScope;
use App\Domain\Dashboard\Enums\DashboardPeriod;
use App\Domain\Dashboard\PipelineFunnel;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\Pipeline;
use App\Domain\Deals\Models\PipelineStage;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Livewire\Dashboard\DashboardActivityFeed;
use App\Livewire\Dashboard\DashboardFunnel;
use App\Livewire\Dashboard\DashboardKpiCards;
use App\Livewire\Dashboard\DashboardTasks;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

/**
 * Somebody who may see the whole CRM.
 *
 * @param  array<int, string>|null  $permissions
 */
function dashboardAdmin(?array $permissions = null, string $level = DataAccessLevel::All->value): User
{
    $permissions ??= [
        'deals.view', 'leads.view', 'contacts.view', 'accounts.view',
        'activities.view', 'activities.create', 'activities.update',
    ];

    $role = Role::query()->create([
        'name' => 'Dashboard '.uniqid(),
        'guard_name' => Guard::getDefaultName(Role::class),
        'data_access_level' => $level,
    ]);

    $role->syncPermissions(PermissionResolver::models($permissions));

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * Somebody holding nothing at all.
 */
function dashboardStranger(): User
{
    return User::factory()->create();
}

function dashboardPipeline(): Pipeline
{
    $pipeline = Pipeline::query()->create([
        'name' => 'Standard',
        'is_default' => true,
        'position' => 1,
    ]);

    $stages = [
        ['key' => DealStage::Qualification->value, 'name' => 'Qualification', 'probability' => 20, 'outcome' => StageOutcome::Open],
        ['key' => DealStage::Proposal->value, 'name' => 'Proposal', 'probability' => 60, 'outcome' => StageOutcome::Open],
        ['key' => DealStage::Won->value, 'name' => 'Closed won', 'probability' => 100, 'outcome' => StageOutcome::Won],
    ];

    foreach ($stages as $position => $stage) {
        PipelineStage::query()->create([
            'pipeline_id' => $pipeline->id,
            'key' => $stage['key'],
            'name' => $stage['name'],
            'probability' => $stage['probability'],
            'outcome' => $stage['outcome']->value,
            'position' => $position + 1,
        ]);
    }

    return $pipeline->fresh();
}

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow('2026-10-15 09:00:00');
    Company::current()->forceFill(['timezone' => 'UTC', 'currency' => 'USD'])->save();
    Cache::flush();
});

afterEach(function () {
    Carbon::setTestNow();
});

// -- The page ------------------------------------------------------------------

test('a guest is sent to sign in', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('the dashboard renders for anybody signed in', function () {
    $this->actingAs(dashboardAdmin())->get(route('dashboard'))->assertOk()->assertSee('Dashboard');

    // Even somebody holding no module permission at all: the page is their
    // landing page, and a 403 on it would lock them out of the application.
    $this->actingAs(dashboardStranger())->get(route('dashboard'))->assertOk();
});

test('every widget ships a skeleton placeholder', function (string $component) {
    $placeholder = app($component)->placeholder();

    expect($placeholder)->toContain('animate-pulse')
        // Colour is never the only signal, and a screen reader gets nothing
        // from a pulsing grey box.
        ->toContain('sr-only');
})->with([
    DashboardKpiCards::class,
    DashboardFunnel::class,
    DashboardActivityFeed::class,
    DashboardTasks::class,
]);

// -- KPI cards -----------------------------------------------------------------

test('the kpi cards render with no data at all', function () {
    Livewire::withoutLazyLoading()
        ->actingAs(dashboardAdmin())
        ->test(DashboardKpiCards::class)
        ->assertOk()
        ->assertSee('Open deals')
        ->assertSee('New leads')
        ->assertSee('Overdue');
});

test('the kpi cards count what the viewer can see', function () {
    $user = dashboardAdmin();
    dashboardPipeline();

    Deal::factory()->count(3)->ownedBy($user)->create(['stage' => DealStage::Proposal->value, 'value' => 1000]);
    Lead::factory()->count(2)->ownedBy($user)->create();

    $cards = app(DashboardKpis::class)->for(new DashboardScope($user), DashboardPeriod::Month);
    $byKey = collect($cards)->keyBy('key');

    expect($byKey['open_deals']->raw)->toBe(3)
        ->and($byKey['open_pipeline']->raw)->toBe(3000.0)
        ->and($byKey['new_leads']->raw)->toBe(2);
});

test('a card is left out entirely when its module is, rather than showing zero', function () {
    // A zero says "there are none", which is a different and wrong answer.
    $user = dashboardAdmin(['leads.view']);

    $keys = collect(app(DashboardKpis::class)->for(new DashboardScope($user), DashboardPeriod::Month))
        ->pluck('key')
        ->all();

    expect($keys)->toContain('new_leads')
        ->and($keys)->not->toContain('open_deals')
        ->and($keys)->not->toContain('open_pipeline')
        ->and($keys)->not->toContain('overdue_activities');
});

test('the figures respect the data access level', function () {
    $owner = dashboardAdmin(level: DataAccessLevel::Own->value);
    $peer = dashboardAdmin(level: DataAccessLevel::Own->value);

    Deal::factory()->ownedBy($owner)->create(['stage' => DealStage::Proposal->value, 'value' => 500]);
    Deal::factory()->ownedBy($peer)->create(['stage' => DealStage::Proposal->value, 'value' => 900]);

    $cards = collect(app(DashboardKpis::class)->for(new DashboardScope($owner), DashboardPeriod::Month))->keyBy('key');

    expect($cards['open_deals']->raw)->toBe(1)
        ->and($cards['open_pipeline']->raw)->toBe(500.0);
});

test('won counts by when the deal closed, not when it was created', function () {
    $user = dashboardAdmin();
    dashboardPipeline();

    // Opened long ago, won this month.
    Deal::factory()->ownedBy($user)->create([
        'stage' => DealStage::Won->value,
        'value' => 2500,
        'created_at' => '2026-01-05 09:00:00',
        'closed_at' => '2026-10-02 09:00:00',
    ]);

    // Won last month, so outside the window.
    Deal::factory()->ownedBy($user)->create([
        'stage' => DealStage::Won->value,
        'value' => 4000,
        'closed_at' => '2026-09-20 09:00:00',
    ]);

    $cards = collect(app(DashboardKpis::class)->for(new DashboardScope($user), DashboardPeriod::Month))->keyBy('key');

    expect($cards['won']->raw)->toBe(2500.0)
        ->and($cards['won']->caption)->toBe('1 deal closed');
});

test('a wider period takes in more', function () {
    $user = dashboardAdmin();
    dashboardPipeline();

    Deal::factory()->ownedBy($user)->create(['stage' => DealStage::Won->value, 'value' => 2500, 'closed_at' => '2026-10-02 09:00:00']);
    Deal::factory()->ownedBy($user)->create(['stage' => DealStage::Won->value, 'value' => 4000, 'closed_at' => '2026-03-20 09:00:00']);

    $scope = new DashboardScope($user);

    expect(collect(app(DashboardKpis::class)->for($scope, DashboardPeriod::Month))->keyBy('key')['won']->raw)->toBe(2500.0)
        ->and(collect(app(DashboardKpis::class)->for($scope, DashboardPeriod::Year))->keyBy('key')['won']->raw)->toBe(6500.0);
});

test('the period window is cut on the office clock', function () {
    Company::current()->forceFill(['timezone' => 'Asia/Dhaka'])->save();
    Cache::flush();
    // Just past midnight on 1 October in Dhaka is still 30 September in UTC.
    Carbon::setTestNow('2026-09-30 18:30:00');

    expect(DashboardPeriod::Month->startsAt()->format('Y-m-d H:i'))->toBe('2026-09-30 18:00');
});

test('overdue is not bounded by the period, because late work does not expire', function () {
    $user = dashboardAdmin();

    Activity::factory()->ownedBy($user)->create([
        'due_at' => '2026-03-01 09:00:00',
        'all_day' => false,
    ]);

    $cards = collect(app(DashboardKpis::class)->for(new DashboardScope($user), DashboardPeriod::Month))->keyBy('key');

    expect($cards['overdue_activities']->raw)->toBe(1)
        ->and($cards['overdue_activities']->color)->toBe('rose');
});

test('a zero overdue count is not painted as an alarm', function () {
    $cards = collect(app(DashboardKpis::class)->for(new DashboardScope(dashboardAdmin()), DashboardPeriod::Month))->keyBy('key');

    expect($cards['overdue_activities']->raw)->toBe(0)
        ->and($cards['overdue_activities']->color)->toBe('slate');
});

test('the period switches, and one the dashboard does not offer is ignored', function () {
    Livewire::withoutLazyLoading()
        ->actingAs(dashboardAdmin())
        ->test(DashboardKpiCards::class)
        ->assertSet('period', 'month')
        ->call('setPeriod', 'year')
        ->assertSet('period', 'year')
        ->call('setPeriod', 'fortnight')
        ->assertSet('period', 'year');
});

// -- The funnel ----------------------------------------------------------------

test('the funnel lists every configured stage, including the empty ones', function () {
    $user = dashboardAdmin();
    $pipeline = dashboardPipeline();

    Deal::factory()->count(2)->ownedBy($user)->create(['stage' => DealStage::Proposal->value, 'value' => 100]);

    $bands = app(PipelineFunnel::class)->for(new DashboardScope($user), $pipeline);

    expect($bands)->toHaveCount(3)
        ->and($bands[0]->name)->toBe('Qualification')
        ->and($bands[0]->count)->toBe(0)
        ->and($bands[1]->name)->toBe('Proposal')
        ->and($bands[1]->count)->toBe(2)
        ->and($bands[1]->value)->toBe(200.0);
});

test('a band is drawn against the widest one, not against the total', function () {
    $user = dashboardAdmin();
    $pipeline = dashboardPipeline();

    Deal::factory()->count(4)->ownedBy($user)->create(['stage' => DealStage::Qualification->value]);
    Deal::factory()->count(1)->ownedBy($user)->create(['stage' => DealStage::Proposal->value]);

    $bands = collect(app(PipelineFunnel::class)->for(new DashboardScope($user), $pipeline))->keyBy('key');

    expect($bands[DealStage::Qualification->value]->share)->toBe(100.0)
        ->and($bands[DealStage::Proposal->value]->share)->toBe(25.0);
});

test('the funnel counts deals on the default pipeline that carry no pipeline id', function () {
    // pipeline_id is nullable and null means the default one; nothing backfills
    // it, so a funnel that matched on the column alone would look empty on a
    // working installation.
    $user = dashboardAdmin();
    $pipeline = dashboardPipeline();

    Deal::factory()->ownedBy($user)->create([
        'stage' => DealStage::Proposal->value,
        'pipeline_id' => null,
    ]);

    $bands = collect(app(PipelineFunnel::class)->for(new DashboardScope($user), $pipeline))->keyBy('key');

    expect($bands[DealStage::Proposal->value]->count)->toBe(1);
});

test('a removed deal is not counted in its band', function () {
    $user = dashboardAdmin();
    $pipeline = dashboardPipeline();

    $deal = Deal::factory()->ownedBy($user)->create(['stage' => DealStage::Proposal->value]);
    $deal->delete();

    $bands = collect(app(PipelineFunnel::class)->for(new DashboardScope($user), $pipeline))->keyBy('key');

    expect($bands[DealStage::Proposal->value]->count)->toBe(0);
});

test('the funnel respects the data access level', function () {
    $owner = dashboardAdmin(level: DataAccessLevel::Own->value);
    $peer = dashboardAdmin(level: DataAccessLevel::Own->value);
    $pipeline = dashboardPipeline();

    Deal::factory()->ownedBy($owner)->create(['stage' => DealStage::Proposal->value]);
    Deal::factory()->count(5)->ownedBy($peer)->create(['stage' => DealStage::Proposal->value]);

    $bands = collect(app(PipelineFunnel::class)->for(new DashboardScope($owner), $pipeline))->keyBy('key');

    expect($bands[DealStage::Proposal->value]->count)->toBe(1);
});

test('the funnel is empty for somebody without the deals permission', function () {
    $user = dashboardAdmin(['leads.view']);
    $pipeline = dashboardPipeline();

    Deal::factory()->create(['stage' => DealStage::Proposal->value]);

    expect(app(PipelineFunnel::class)->for(new DashboardScope($user), $pipeline))->toBe([]);

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(DashboardFunnel::class)
        ->assertOk()
        ->assertSee('not yours to see');
});

test('the funnel says so when no pipeline is configured', function () {
    Livewire::withoutLazyLoading()
        ->actingAs(dashboardAdmin())
        ->test(DashboardFunnel::class)
        ->assertOk()
        ->assertSee('No pipeline configured');
});

test('a pipeline id the dashboard does not offer is refused', function () {
    $pipeline = dashboardPipeline();

    Livewire::withoutLazyLoading()
        ->actingAs(dashboardAdmin())
        ->test(DashboardFunnel::class)
        ->call('selectPipeline', 99999)
        ->assertSet('pipelineId', 0)
        ->call('selectPipeline', $pipeline->id)
        ->assertSet('pipelineId', $pipeline->id);
});

test('an unknown pipeline in the url falls back to the default rather than drawing nothing', function () {
    $pipeline = dashboardPipeline();

    $screen = Livewire::withoutLazyLoading()
        ->actingAs(dashboardAdmin())
        ->test(DashboardFunnel::class, ['pipelineId' => 99999]);

    $screen->assertOk()->assertSee($pipeline->name);
});

// -- The activity feed ---------------------------------------------------------

test('the feed renders with no data', function () {
    Livewire::withoutLazyLoading()
        ->actingAs(dashboardAdmin())
        ->test(DashboardActivityFeed::class)
        ->assertOk()
        ->assertSee('Nothing has happened yet');
});

test('the feed shows changes to records the viewer can see', function () {
    $user = dashboardAdmin();

    Account::factory()->ownedBy($user)->create(['name' => 'Northwind Trading']);

    $entries = app(DashboardFeed::class)->for(new DashboardScope($user));

    expect($entries)->not->toBeEmpty()
        ->and($entries[0]->title)->toContain('created');
});

test('the feed never shows a record outside the access level', function () {
    $owner = dashboardAdmin(level: DataAccessLevel::Own->value);
    $peer = dashboardAdmin(level: DataAccessLevel::Own->value);

    Account::factory()->ownedBy($peer)->create(['name' => 'Somebody Elses Account']);

    expect(app(DashboardFeed::class)->for(new DashboardScope($owner)))->toBe([]);

    Livewire::withoutLazyLoading()
        ->actingAs($owner)
        ->test(DashboardActivityFeed::class)
        ->assertOk()
        ->assertDontSee('Somebody Elses Account');
});

test('the feed never shows a module the viewer has no permission for', function () {
    // Can see contacts, not accounts — so an account's entries stay out even
    // though the access level would reach them.
    $user = dashboardAdmin(['contacts.view']);

    Account::factory()->create(['name' => 'Northwind Trading']);

    expect(app(DashboardFeed::class)->for(new DashboardScope($user)))->toBe([]);
});

test('a viewer with no modules at all gets an explanation, not an empty list', function () {
    Livewire::withoutLazyLoading()
        ->actingAs(dashboardStranger())
        ->test(DashboardActivityFeed::class)
        ->assertOk()
        ->assertSee('Nothing to show');
});

test('the feed loads more, up to a ceiling', function () {
    $user = dashboardAdmin();

    Account::factory()->count(DashboardActivityFeed::PAGE_SIZE + 3)->ownedBy($user)->create();

    $screen = Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(DashboardActivityFeed::class)
        ->assertSet('visible', DashboardActivityFeed::PAGE_SIZE)
        ->call('loadMore')
        ->assertSet('visible', DashboardActivityFeed::PAGE_SIZE * 2);

    for ($i = 0; $i < 10; $i++) {
        $screen->call('loadMore');
    }

    $screen->assertSet('visible', DashboardActivityFeed::MAX_VISIBLE);
});

// -- My tasks ------------------------------------------------------------------

test('my tasks renders with no data', function () {
    Livewire::withoutLazyLoading()
        ->actingAs(dashboardAdmin())
        ->test(DashboardTasks::class)
        ->assertOk()
        ->assertSee('Nothing on your list');
});

test('my tasks means mine, not everything I can see', function () {
    $user = dashboardAdmin();
    $colleague = dashboardAdmin();

    Activity::factory()->ownedBy($user)->create(['subject' => 'Call Dana', 'due_at' => '2026-10-16 09:00:00']);
    Activity::factory()->ownedBy($colleague)->create(['subject' => 'Not my problem', 'due_at' => '2026-10-16 09:00:00']);

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(DashboardTasks::class)
        ->assertSee('Call Dana')
        ->assertDontSee('Not my problem');
});

test('my tasks are soonest first, and completed ones are gone', function () {
    $user = dashboardAdmin();

    Activity::factory()->ownedBy($user)->create(['subject' => 'Later', 'due_at' => '2026-10-20 09:00:00']);
    Activity::factory()->ownedBy($user)->create(['subject' => 'Sooner', 'due_at' => '2026-10-16 09:00:00']);
    Activity::factory()->ownedBy($user)->completed()->create(['subject' => 'Already done', 'due_at' => '2026-10-17 09:00:00']);

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(DashboardTasks::class)
        ->assertSeeInOrder(['Sooner', 'Later'])
        ->assertDontSee('Already done');
});

test('the list is capped and says how many more there are', function () {
    $user = dashboardAdmin();

    Activity::factory()->count(DashboardTasks::LIMIT + 4)->ownedBy($user)
        ->create(['due_at' => '2026-10-20 09:00:00']);

    $screen = Livewire::withoutLazyLoading()->actingAs($user)->test(DashboardTasks::class);

    expect($screen->instance()->tasks())->toHaveCount(DashboardTasks::LIMIT)
        ->and($screen->instance()->openCount())->toBe(DashboardTasks::LIMIT + 4);

    $screen->assertSee('4 more on your list');
});

test('a task can be ticked off from the dashboard', function () {
    $user = dashboardAdmin();
    $task = Activity::factory()->ownedBy($user)->create(['subject' => 'Call Dana', 'due_at' => '2026-10-16 09:00:00']);

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(DashboardTasks::class)
        ->call('complete', $task->id)
        ->assertDispatched('notify');

    expect($task->fresh()->isCompleted())->toBeTrue();
});

test('a task outside the access level cannot be completed by guessing its id', function () {
    $user = dashboardAdmin(level: DataAccessLevel::Own->value);
    $peer = dashboardAdmin(level: DataAccessLevel::Own->value);

    $theirs = Activity::factory()->ownedBy($peer)->create(['subject' => 'Not mine']);

    Livewire::withoutLazyLoading()
        ->actingAs($user)
        ->test(DashboardTasks::class)
        ->call('complete', $theirs->id);

    expect($theirs->fresh()->isCompleted())->toBeFalse();
});

test('somebody who may only look is not offered the tick', function () {
    $viewer = dashboardAdmin(['activities.view']);
    Activity::factory()->ownedBy($viewer)->create(['subject' => 'Call Dana', 'due_at' => '2026-10-16 09:00:00']);

    Livewire::withoutLazyLoading()
        ->actingAs($viewer)
        ->test(DashboardTasks::class)
        ->assertSee('Call Dana')
        ->assertDontSee('Mark Call Dana as done');
});

test('somebody without the activities permission is told so', function () {
    Livewire::withoutLazyLoading()
        ->actingAs(dashboardAdmin(['deals.view']))
        ->test(DashboardTasks::class)
        ->assertOk()
        ->assertSee('not yours to see');
});
