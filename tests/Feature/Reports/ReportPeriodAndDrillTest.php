<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Accounts\Models\Account;
use App\Domain\Company\Models\Company;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\Pipeline;
use App\Domain\Deals\Models\PipelineStage;
use App\Domain\Reports\Enums\DatePeriod;
use App\Domain\Reports\Models\Report;
use App\Domain\Reports\ReportDefinition;
use App\Domain\Reports\ReportResult;
use App\Domain\Reports\ReportRow;
use App\Domain\Reports\ReportRunner;
use App\Domain\Sales\Models\Quote;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Domain\Shared\RequestMemo;
use App\Livewire\Reports\ReportBuilder;
use App\Livewire\Reports\ReportShow;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

function periodReportUser(DataAccessLevel $level = DataAccessLevel::All): User
{
    $role = Role::query()->create([
        'name' => 'Reporting '.$level->value.' '.uniqid(),
        'guard_name' => Guard::getDefaultName(Role::class),
        'data_access_level' => $level->value,
    ]);

    $user = User::factory()->create();
    $user->assignRole($role);

    foreach (PermissionResolver::models(['deals.view', 'accounts.view', 'quotes.view', 'reports.view', 'reports.create', 'reports.update']) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

/**
 * @param  array<string, mixed>  $state
 */
function runPeriodReport(array $state, User $viewer): ReportResult
{
    return app(ReportRunner::class)->run(ReportDefinition::fromArray($state), $viewer);
}

beforeEach(function () {
    Company::current()->update(['timezone' => 'Asia/Dhaka']);
    Carbon::setTestNow(Carbon::parse('2026-09-25 10:00:00', 'Asia/Dhaka'));
});

afterEach(function () {
    Carbon::setTestNow();
});

// -- Periods ------------------------------------------------------------------------

test('each period covers the days it says', function (DatePeriod $period, string $from, string $to) {
    [$start, $end] = $period->days(Carbon::parse('2026-09-25', 'Asia/Dhaka'));

    expect($start->toDateString())->toBe($from)
        ->and($end->toDateString())->toBe($to);
})->with([
    'this month' => [DatePeriod::ThisMonth, '2026-09-01', '2026-09-30'],
    'last month' => [DatePeriod::LastMonth, '2026-08-01', '2026-08-31'],
    'this quarter' => [DatePeriod::ThisQuarter, '2026-07-01', '2026-09-30'],
    'last quarter' => [DatePeriod::LastQuarter, '2026-04-01', '2026-06-30'],
    'this week starts monday' => [DatePeriod::ThisWeek, '2026-09-21', '2026-09-27'],
    'last 7 days includes today' => [DatePeriod::Last7Days, '2026-09-19', '2026-09-25'],
    'last year' => [DatePeriod::LastYear, '2025-01-01', '2025-12-31'],
]);

test('a custom range typed backwards is the same range, and a nonsense date is ignored', function () {
    $today = Carbon::parse('2026-09-25', 'Asia/Dhaka');

    [$from, $to] = DatePeriod::Custom->days($today, '2026-09-30', '2026-09-01');

    expect($from->toDateString())->toBe('2026-09-01')
        ->and($to->toDateString())->toBe('2026-09-30')
        ->and(DatePeriod::Custom->days($today, '2026-02-31', null))->toBeNull()
        ->and(DatePeriod::AllTime->days($today))->toBeNull();
});

test('a period narrows the rows and the totals alike', function () {
    $viewer = periodReportUser();

    Deal::factory()->ownedBy($viewer)->create(['stage' => DealStage::Won->value, 'value' => 100, 'closed_at' => '2026-08-15 06:00:00']);
    Deal::factory()->ownedBy($viewer)->create(['stage' => DealStage::Won->value, 'value' => 900, 'closed_at' => '2026-09-10 06:00:00']);

    $result = runPeriodReport([
        'source' => 'deals', 'dimensions' => ['stage'], 'measures' => ['value'],
        'period' => 'last_month', 'date_field' => 'closed',
    ], $viewer);

    expect($result->rows)->toHaveCount(1)
        ->and($result->rows[0]->value('value'))->toBe(100.0)
        ->and($result->totals['value'])->toBe(100.0);
});

test('a month is the office\'s month, not UTC\'s', function () {
    // 19:30 UTC on 31 August is 01:30 on 1 September in Dhaka.
    $viewer = periodReportUser();
    Deal::factory()->ownedBy($viewer)->create(['stage' => DealStage::Won->value, 'value' => 500, 'closed_at' => '2026-08-31 19:30:00']);

    $september = runPeriodReport(['source' => 'deals', 'measures' => ['value'], 'period' => 'this_month', 'date_field' => 'closed'], $viewer);
    $august = runPeriodReport(['source' => 'deals', 'measures' => ['value'], 'period' => 'last_month', 'date_field' => 'closed'], $viewer);

    expect($september->totals['value'])->toBe(500.0)
        ->and($august->totals['value'])->toEqual(0);
});

test('a date-only column is compared as a calendar day', function () {
    $viewer = periodReportUser();
    Deal::factory()->ownedBy($viewer)->create(['value' => 300, 'expected_close_date' => '2026-09-30']);
    Deal::factory()->ownedBy($viewer)->create(['value' => 700, 'expected_close_date' => '2026-10-01']);

    $result = runPeriodReport(['source' => 'deals', 'measures' => ['value'], 'period' => 'this_month', 'date_field' => 'expected_close'], $viewer);

    expect($result->totals['value'])->toBe(300.0);
});

test('a period can only be measured on a declared date', function () {
    // "stage" is a dimension but not a date, so it cannot carry a period; the
    // source's first date is used instead rather than the key reaching SQL.
    $viewer = periodReportUser();
    Deal::factory()->ownedBy($viewer)->create(['value' => 10, 'created_at' => '2025-01-10 06:00:00']);
    Deal::factory()->ownedBy($viewer)->create(['value' => 20, 'created_at' => '2026-09-10 06:00:00']);

    $result = runPeriodReport(['source' => 'deals', 'measures' => ['value'], 'period' => 'this_month', 'date_field' => 'stage'], $viewer);

    expect($result->totals['value'])->toBe(20.0);
});

// -- The alias collision --------------------------------------------------------------

test('a dimension and a measure sharing a key keep their own values', function () {
    // Quotes group by the date they were accepted and count how many were;
    // one alias for both printed "1" where the month should be.
    $viewer = periodReportUser();
    Quote::factory()->ownedBy($viewer)->create(['total' => 100, 'accepted_at' => '2026-09-10 06:00:00']);

    $result = runPeriodReport(['source' => 'quotes', 'dimensions' => ['accepted'], 'measures' => ['accepted']], $viewer);

    expect($result->rows[0]->group('accepted'))->toBe('2026-09')
        ->and($result->rows[0]->value('accepted'))->toBe(1);
});

// -- Measures ---------------------------------------------------------------------------

test('won and lost follow the pipeline\'s own closing stages', function () {
    $viewer = periodReportUser();
    $pipeline = Pipeline::factory()->create(['module' => Pipeline::DEALS]);
    PipelineStage::factory()->for($pipeline)->create(['key' => 'signed', 'outcome' => StageOutcome::Won->value, 'position' => 99]);
    app(RequestMemo::class)->flush();

    Deal::factory()->ownedBy($viewer)->create(['stage' => 'signed', 'value' => 400]);
    Deal::factory()->ownedBy($viewer)->create(['stage' => DealStage::Won->value, 'value' => 100]);
    Deal::factory()->ownedBy($viewer)->create(['stage' => DealStage::Lost->value, 'value' => 50]);
    Deal::factory()->ownedBy($viewer)->create(['stage' => 'proposal', 'value' => 1000]);

    $totals = runPeriodReport(['source' => 'deals', 'measures' => ['won_value', 'won_count', 'lost_count', 'win_rate', 'open_value']], $viewer)->totals;

    expect($totals['won_value'])->toBe(500.0)
        ->and($totals['won_count'])->toBe(2)
        ->and($totals['lost_count'])->toBe(1)
        ->and(round((float) $totals['win_rate'], 1))->toBe(66.7)
        ->and($totals['open_value'])->toBe(1000.0);
});

test('a win rate with nothing closed is unknown, not nought', function () {
    $viewer = periodReportUser();
    Deal::factory()->ownedBy($viewer)->create(['stage' => 'proposal', 'value' => 1000]);

    expect(runPeriodReport(['source' => 'deals', 'measures' => ['win_rate']], $viewer)->totals['win_rate'])->toBeNull();
});

// -- Drilling in ------------------------------------------------------------------------

test('the records behind a row are exactly the ones it counted', function () {
    $viewer = periodReportUser();
    $acme = Account::factory()->create(['name' => 'Acme']);
    $other = Account::factory()->create(['name' => 'Other']);

    Deal::factory()->ownedBy($viewer)->create(['account_id' => $acme->id, 'name' => 'Big order', 'value' => 900]);
    Deal::factory()->ownedBy($viewer)->create(['account_id' => $acme->id, 'name' => 'Small order', 'value' => 100]);
    Deal::factory()->ownedBy($viewer)->create(['account_id' => $other->id, 'name' => 'Elsewhere', 'value' => 50]);

    $definition = ReportDefinition::fromArray(['source' => 'deals', 'dimensions' => ['account'], 'measures' => ['value', 'count']]);
    $row = collect(app(ReportRunner::class)->run($definition, $viewer)->rows)->first(fn ($row) => $row->id('account') === $acme->id);

    $records = app(ReportRunner::class)->records($definition, $viewer, $row->drill());

    expect(array_column($records->items, 'label'))->toBe(['Big order', 'Small order'])
        ->and(array_sum(array_map(fn ($item) => $item['values']['value'], $records->items)))->toBe($row->value('value'))
        // A count has no per-record value, so it is not a column here.
        ->and(array_keys($records->measures))->toBe(['value'])
        ->and($records->items[0]['url'])->toBe(route('deals.show', Deal::query()->where('name', 'Big order')->value('id')));
});

test('a drill never reaches past the viewer\'s own records', function () {
    $viewer = periodReportUser(DataAccessLevel::Own);
    $account = Account::factory()->create();

    Deal::factory()->ownedBy($viewer)->create(['account_id' => $account->id, 'name' => 'Mine']);
    Deal::factory()->create(['account_id' => $account->id, 'name' => 'Not mine']);

    $definition = ReportDefinition::fromArray(['source' => 'deals', 'dimensions' => ['account'], 'measures' => ['count']]);
    $records = app(ReportRunner::class)->records($definition, $viewer, ['account' => (string) $account->id]);

    expect(array_column($records->items, 'label'))->toBe(['Mine']);
});

test('a drill on a key the report does not group by is ignored', function () {
    $viewer = periodReportUser();
    Deal::factory()->count(2)->ownedBy($viewer)->create();

    $definition = ReportDefinition::fromArray(['source' => 'deals', 'dimensions' => ['stage'], 'measures' => ['count']]);
    $records = app(ReportRunner::class)->records($definition, $viewer, ['owner' => '999999']);

    expect($records->items)->toHaveCount(2);
});

test('the group with no value can be drilled into too', function () {
    $viewer = periodReportUser();
    Deal::factory()->ownedBy($viewer)->create(['name' => 'Still open', 'closed_at' => null]);
    Deal::factory()->ownedBy($viewer)->create(['name' => 'Closed', 'stage' => DealStage::Won->value, 'closed_at' => '2026-09-01 06:00:00']);

    $definition = ReportDefinition::fromArray(['source' => 'deals', 'dimensions' => ['closed'], 'measures' => ['count']]);
    $records = app(ReportRunner::class)->records($definition, $viewer, ['closed' => ReportRow::NONE]);

    expect(array_column($records->items, 'label'))->toBe(['Still open']);
});

// -- The report page --------------------------------------------------------------------

test('the report page takes its period from the address', function () {
    $viewer = periodReportUser();
    Deal::factory()->ownedBy($viewer)->create(['stage' => DealStage::Won->value, 'value' => 100, 'closed_at' => '2026-08-15 06:00:00']);
    Deal::factory()->ownedBy($viewer)->create(['stage' => DealStage::Won->value, 'value' => 900, 'closed_at' => '2026-09-10 06:00:00']);

    $report = Report::factory()->ownedBy($viewer)->create([
        'source' => 'deals',
        'definition' => ['source' => 'deals', 'measures' => ['value'], 'date_field' => 'closed'],
    ]);

    $component = Livewire::withQueryParams(['period' => 'last_month'])
        ->actingAs($viewer)
        ->test(ReportShow::class, ['report' => $report]);

    expect($component->instance()->result->totals['value'])->toBe(100.0);

    // Reading another period does not change what the report was saved with.
    expect($report->fresh()->definition()->period)->toBe(DatePeriod::AllTime);
});

test('an account name links to the account, but only one the reader may open', function () {
    $viewer = periodReportUser(DataAccessLevel::Own);
    $mine = Account::factory()->ownedBy($viewer)->create(['name' => 'Mine Co']);
    $theirs = Account::factory()->create(['name' => 'Their Co']);

    Deal::factory()->ownedBy($viewer)->create(['account_id' => $mine->id]);
    Deal::factory()->ownedBy($viewer)->create(['account_id' => $theirs->id]);

    $report = Report::factory()->ownedBy($viewer)->create([
        'source' => 'deals',
        'definition' => ['source' => 'deals', 'dimensions' => ['account'], 'measures' => ['count']],
    ]);

    Livewire::actingAs($viewer)
        ->test(ReportShow::class, ['report' => $report])
        ->assertSee(route('accounts.show', $mine))
        ->assertDontSee(route('accounts.show', $theirs))
        // Still listed by name: the aggregate is the viewer's own deals.
        ->assertSee('Their Co');
});

test('drilling from the page lists the deals behind a salesperson, and the address keeps it', function () {
    $viewer = periodReportUser();
    Deal::factory()->ownedBy($viewer)->create(['name' => 'Karim deal', 'stage' => DealStage::Won->value, 'value' => 500]);

    $report = Report::factory()->ownedBy($viewer)->create([
        'source' => 'deals',
        'definition' => ['source' => 'deals', 'dimensions' => ['owner'], 'measures' => ['won_value']],
    ]);

    Livewire::actingAs($viewer)
        ->test(ReportShow::class, ['report' => $report])
        ->call('drillInto', ['owner' => (string) $viewer->id])
        ->assertSet('drill', ['owner' => (string) $viewer->id])
        ->assertSee('Karim deal')
        ->assertSee('Owner: '.$viewer->name)
        ->call('clearDrill')
        ->assertSet('drill', []);
});

test('the builder keeps a row limit and a period when it saves', function () {
    $viewer = periodReportUser();

    Livewire::actingAs($viewer)
        ->test(ReportBuilder::class)
        ->set('name', 'Top ten last quarter')
        ->set('source', 'deals')
        ->call('addDimension', 'account')
        ->call('addMeasure', 'won_value')
        ->set('limit', '10')
        ->set('period', 'last_quarter')
        ->set('dateField', 'closed')
        ->call('save')
        ->assertHasNoErrors();

    $definition = Report::query()->where('name', 'Top ten last quarter')->sole()->definition();

    expect($definition->limit)->toBe(10)
        ->and($definition->period)->toBe(DatePeriod::LastQuarter)
        ->and($definition->dateField)->toBe('closed');
});
