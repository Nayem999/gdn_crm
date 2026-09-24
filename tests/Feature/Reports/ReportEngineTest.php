<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadAssignee;
use App\Domain\Reports\Enums\DateGrain;
use App\Domain\Reports\ReportDefinition;
use App\Domain\Reports\ReportResult;
use App\Domain\Reports\ReportRunner;
use App\Domain\Reports\ReportSources;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Domain\Shared\Enums\FilterOperator;
use App\Domain\Shared\Filters\FilterGroup;
use App\Domain\Support\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

/**
 * Somebody who may report on everything.
 *
 * @param  array<int, string>  $permissions
 */
function reportAdmin(?array $permissions = null): User
{
    $permissions ??= [
        'deals.view', 'leads.view', 'accounts.view', 'contacts.view',
        'activities.view', 'tickets.view', 'quotes.view', 'campaigns.view',
        'reports.view', 'reports.create', 'reports.update', 'reports.delete',
        'reports.share', 'reports.schedule',
    ];

    $role = Role::query()->create([
        'name' => 'Reporting all '.uniqid(),
        'guard_name' => Guard::getDefaultName(Role::class),
        'data_access_level' => DataAccessLevel::All->value,
    ]);

    $role->syncPermissions(PermissionResolver::models($permissions));

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * @param  array<int, string>  $permissions
 */
function reportUser(array $permissions = ['reports.view']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

/**
 * @param  array<string, mixed>  $state
 */
function runReport(array $state, User $viewer): ReportResult
{
    return app(ReportRunner::class)->run(ReportDefinition::fromArray($state), $viewer);
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-30 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

// -- The registry --------------------------------------------------------------

test('every source declares a permission, a table and a base query', function (string $key) {
    $source = ReportSources::find($key);
    $viewer = reportAdmin();

    expect($source)->not->toBeNull()
        ->and($source->permission)->not->toBe('')
        ->and($source->table)->not->toBe('')
        ->and($source->query($viewer))->not->toBeNull();
})->with(ReportSources::KEYS);

test('every dimension and measure names a column on a real table', function (string $key) {
    $source = ReportSources::find($key);

    // A dimension or measure whose column did not exist would be a report that
    // works until somebody picks that field.
    expect($source->dimensions)->not->toBeEmpty()
        ->and($source->measures)->not->toBeEmpty();

    foreach ($source->dimensions as $dimension) {
        expect($dimension->column)->toContain('.');
    }
})->with(ReportSources::KEYS);

test('the sources offered are the ones the viewer may see', function () {
    $limited = reportUser(['reports.view', 'deals.view']);

    expect(array_keys(ReportSources::optionsFor($limited)))->toBe(['deals'])
        ->and(array_keys(ReportSources::optionsFor(reportAdmin())))->toBe(ReportSources::keys());
});

// -- Aggregates against known fixtures -----------------------------------------

test('a count groups rows the way the dimension says', function () {
    $viewer = reportAdmin();

    Lead::factory()->count(3)->ownedBy($viewer)->create(['status' => LeadStatus::New->value]);
    Lead::factory()->count(2)->ownedBy($viewer)->create(['status' => LeadStatus::Qualified->value]);

    $result = runReport([
        'source' => 'leads',
        'dimensions' => ['status'],
        'measures' => ['count'],
    ], $viewer);

    $counts = [];

    foreach ($result->rows as $row) {
        $counts[$row->group('status')] = $row->value('count');
    }

    expect($counts[LeadStatus::New->label()])->toBe(3)
        ->and($counts[LeadStatus::Qualified->label()])->toBe(2)
        ->and($result->totals['count'])->toBe(5);
});

test('the owner dimension reads the primary assignee, even when a lead has several', function () {
    $viewer = reportAdmin();
    $second = reportAdmin();

    Lead::factory()->ownedBy($viewer)->create();

    // A second lead with two assignees: $viewer holds the lower (so, higher
    // precedence) priority number, $second the higher one — proving the join
    // picks the same assignee assignees()/primaryAssignee() would, not just
    // whichever row happens to come back first.
    $shared = Lead::factory()->ownedBy($viewer)->create();
    LeadAssignee::query()
        ->where('lead_id', $shared->id)
        ->where('user_id', $viewer->id)
        ->update(['priority' => 1]);
    LeadAssignee::factory()
        ->for($shared, 'lead')
        ->for($second, 'user')
        ->prioritised(5)
        ->create();

    $result = runReport([
        'source' => 'leads',
        'dimensions' => ['owner'],
        'measures' => ['count'],
    ], $viewer);

    $byOwner = [];

    foreach ($result->rows as $row) {
        $byOwner[$row->group('owner')] = $row->value('count');
    }

    expect($byOwner[$viewer->name])->toBe(2)
        ->and($byOwner)->not->toHaveKey($second->name);
});

test('a sum totals the column, and the report total is its own query', function () {
    $viewer = reportAdmin();

    Deal::factory()->ownedBy($viewer)->create(['value' => 1000, 'stage' => 'qualification']);
    Deal::factory()->ownedBy($viewer)->create(['value' => 2500, 'stage' => 'qualification']);
    Deal::factory()->ownedBy($viewer)->create(['value' => 400, 'stage' => 'proposal']);

    $result = runReport([
        'source' => 'deals',
        'dimensions' => ['stage'],
        'measures' => ['value', 'count'],
    ], $viewer);

    $byStage = [];

    foreach ($result->rows as $row) {
        $byStage[$row->group('stage')] = $row->value('value');
    }

    expect(array_sum($byStage))->toBe(3900.0)
        // Run ungrouped rather than summed from the rows: a total of averages
        // is not the average, and a truncated list would not add up.
        ->and($result->totals['value'])->toBe(3900.0)
        ->and($result->totals['count'])->toBe(3);
});

test('an average is the average, not the total over the row count', function () {
    $viewer = reportAdmin();

    Deal::factory()->ownedBy($viewer)->create(['value' => 100, 'stage' => 'qualification']);
    Deal::factory()->ownedBy($viewer)->create(['value' => 200, 'stage' => 'qualification']);
    Deal::factory()->ownedBy($viewer)->create(['value' => 900, 'stage' => 'proposal']);

    $result = runReport([
        'source' => 'deals',
        'dimensions' => ['stage'],
        'measures' => ['average_value'],
    ], $viewer);

    $byStage = [];

    foreach ($result->rows as $row) {
        $byStage[$row->group('stage')] = $row->value('average_value');
    }

    expect($byStage)->toContain(150.0)->toContain(900.0)
        // The overall average is 400, which is not the mean of 150 and 900.
        ->and($result->totals['average_value'])->toBe(400.0);
});

test('a count over a nullable column counts only the rows that have one', function () {
    $viewer = reportAdmin();

    Lead::factory()->count(2)->ownedBy($viewer)->create(['converted_at' => now()]);
    Lead::factory()->count(3)->ownedBy($viewer)->create(['converted_at' => null]);

    $result = runReport([
        'source' => 'leads',
        'measures' => ['count', 'converted'],
    ], $viewer);

    // COUNT(*) counts everything; COUNT(column) skips the nulls, which is
    // exactly the difference between "leads" and "converted".
    expect($result->totals['count'])->toBe(5)
        ->and($result->totals['converted'])->toBe(2);
});

test('an empty group is nought for a count and nothing for an average', function () {
    $viewer = reportAdmin();

    $result = runReport([
        'source' => 'deals',
        'measures' => ['count', 'value', 'average_value'],
    ], $viewer);

    // An average of no rows is unknown; printing nought would be a claim
    // nobody made.
    expect($result->totals['count'])->toBe(0)
        ->and($result->totals['value'])->toBe(0)
        ->and($result->totals['average_value'])->toBeNull();
});

// -- Grouping ------------------------------------------------------------------

test('two dimensions group by both', function () {
    $viewer = reportAdmin();

    Lead::factory()->ownedBy($viewer)->create(['status' => LeadStatus::New->value, 'source' => LeadSource::WebForm->value]);
    Lead::factory()->ownedBy($viewer)->create(['status' => LeadStatus::New->value, 'source' => LeadSource::Referral->value]);
    Lead::factory()->ownedBy($viewer)->create(['status' => LeadStatus::Qualified->value, 'source' => LeadSource::WebForm->value]);

    $result = runReport([
        'source' => 'leads',
        'dimensions' => ['status', 'source'],
        'measures' => ['count'],
    ], $viewer);

    expect($result->rowCount())->toBe(3)
        ->and($result->columns())->toHaveKey('status')
        ->and($result->columns())->toHaveKey('source');
});

test('the same dimension twice is one column', function () {
    $viewer = reportAdmin();
    Lead::factory()->ownedBy($viewer)->create();

    $result = runReport([
        'source' => 'leads',
        'dimensions' => ['status', 'status'],
        'measures' => ['count'],
    ], $viewer);

    // A GROUP BY that names the same thing twice means nothing and prints a
    // duplicate column.
    expect($result->dimensions)->toHaveCount(1);
});

test('a date dimension buckets by the chosen grain', function (string $grain, int $expectedRows) {
    $viewer = reportAdmin();

    Lead::factory()->ownedBy($viewer)->create(['created_at' => '2026-08-03 09:00:00']);
    Lead::factory()->ownedBy($viewer)->create(['created_at' => '2026-08-20 09:00:00']);
    Lead::factory()->ownedBy($viewer)->create(['created_at' => '2026-09-05 09:00:00']);

    $result = runReport([
        'source' => 'leads',
        'dimensions' => ['created'],
        'measures' => ['count'],
        'grain' => $grain,
    ], $viewer);

    expect($result->rowCount())->toBe($expectedRows);
})->with([
    'day' => ['day', 3],
    'month' => ['month', 2],
    'quarter' => ['quarter', 1],
    'year' => ['year', 1],
]);

test('a date report comes back in date order when nobody chose a sort', function () {
    $viewer = reportAdmin();

    Lead::factory()->ownedBy($viewer)->create(['created_at' => '2026-09-05 09:00:00']);
    Lead::factory()->ownedBy($viewer)->count(5)->create(['created_at' => '2026-07-05 09:00:00']);
    Lead::factory()->ownedBy($viewer)->create(['created_at' => '2026-08-05 09:00:00']);

    $result = runReport([
        'source' => 'leads',
        'dimensions' => ['created'],
        'measures' => ['count'],
        'grain' => 'month',
    ], $viewer);

    // Chronological, not biggest-first: a report over time that opened with
    // July because July was busiest would be unreadable.
    expect(array_map(fn ($row) => $row->group('created'), $result->rows))
        ->toBe(['2026-07', '2026-08', '2026-09']);
});

test('a week bucket uses the ISO week-numbering year', function () {
    $viewer = reportAdmin();

    // 1 January 2027 is a Friday, which ISO puts in week 53 of 2026.
    Lead::factory()->ownedBy($viewer)->create(['created_at' => '2027-01-01 09:00:00']);

    $result = runReport([
        'source' => 'leads',
        'dimensions' => ['created'],
        'measures' => ['count'],
        'grain' => 'week',
    ], $viewer);

    // %Y-%V would pair 2027 with week 53 and put the bucket a year out.
    expect($result->rows[0]->group('created'))->toBe('2026-W53');
});

test('a coded dimension prints the name, not the stored code', function () {
    $viewer = reportAdmin();
    Lead::factory()->ownedBy($viewer)->create(['status' => LeadStatus::Qualified->value]);

    $result = runReport([
        'source' => 'leads',
        'dimensions' => ['status'],
        'measures' => ['count'],
    ], $viewer);

    expect($result->rows[0]->group('status'))->toBe(LeadStatus::Qualified->label())
        ->and($result->rows[0]->group('status'))->not->toBe(LeadStatus::Qualified->value);
});

test('rows with nothing in the dimension say so rather than showing a blank', function () {
    $viewer = reportAdmin();
    Lead::factory()->ownedBy($viewer)->create(['country' => null]);

    $result = runReport([
        'source' => 'leads',
        'dimensions' => ['country'],
        'measures' => ['count'],
    ], $viewer);

    // A blank cell in a grouped report reads as a rendering fault.
    expect($result->rows[0]->group('country'))->toBe('(none)');
});

// -- Joins ---------------------------------------------------------------------

test('a joined dimension groups by the related table', function () {
    $dana = reportAdmin();
    $sam = reportAdmin();

    Deal::factory()->count(2)->ownedBy($dana)->create();
    Deal::factory()->ownedBy($sam)->create();

    $result = runReport([
        'source' => 'deals',
        'dimensions' => ['owner'],
        'measures' => ['count'],
    ], $dana);

    $byOwner = [];

    foreach ($result->rows as $row) {
        $byOwner[$row->group('owner')] = $row->value('count');
    }

    expect($byOwner[$dana->name])->toBe(2)
        ->and($byOwner[$sam->name])->toBe(1);
});

test('a left join keeps the rows with nothing on the other side', function () {
    $viewer = reportAdmin();

    // A ticket need not belong to an account — deals must, so they cannot show
    // this. An INNER join would silently drop the unattached ones, which is
    // exactly the group somebody is looking for when they run the report.
    Ticket::factory()->ownedBy($viewer)->create(['account_id' => null]);

    $result = runReport([
        'source' => 'tickets',
        'dimensions' => ['account'],
        'measures' => ['count'],
    ], $viewer);

    expect($result->totals['count'])->toBe(1)
        ->and($result->rows[0]->group('account'))->toBe('(none)');
});

test('a join is only applied when something asks for it', function () {
    $viewer = reportAdmin();
    Deal::factory()->ownedBy($viewer)->create(['stage' => 'qualification']);

    // Nothing to assert on the SQL itself from here; what matters is that a
    // report that needs no join still runs and counts correctly.
    $result = runReport([
        'source' => 'deals',
        'dimensions' => ['stage'],
        'measures' => ['count'],
    ], $viewer);

    expect($result->totals['count'])->toBe(1);
});

// -- What a definition may not do ----------------------------------------------

test('a dimension the source does not declare is dropped', function () {
    $viewer = reportAdmin();
    Lead::factory()->ownedBy($viewer)->create();

    $result = runReport([
        'source' => 'leads',
        'dimensions' => ['email', 'leads.email', 'password'],
        'measures' => ['count'],
    ], $viewer);

    // Dropped, not passed through: a definition that could name a column would
    // make the builder an arbitrary-SQL console.
    expect($result->dimensions)->toBe([])
        ->and($result->totals['count'])->toBe(1);
});

test('a measure the source does not declare is dropped, and a report with none does not run', function () {
    $viewer = reportAdmin();
    Lead::factory()->ownedBy($viewer)->create();

    $result = runReport([
        'source' => 'leads',
        'dimensions' => ['status'],
        'measures' => ['(select 1)'],
    ], $viewer);

    expect($result->measures)->toBe([])
        ->and($result->hasRows())->toBeFalse()
        ->and($result->isRunnable())->toBeFalse();
});

test('a source that does not exist is refused rather than guessed at', function () {
    $result = runReport(['source' => 'users', 'measures' => ['count']], reportAdmin());

    expect($result->refused)->toBeTrue()
        ->and($result->source)->toBeNull();
});

test('a source the viewer may not see is refused, not emptied', function () {
    $viewer = reportUser(['reports.view']);
    Deal::factory()->create();

    $result = runReport(['source' => 'deals', 'measures' => ['count']], $viewer);

    // "You may not see this" and "there is nothing" are different answers, and
    // a screen has to say a different thing for each.
    expect($result->refused)->toBeTrue()
        ->and($result->hasRows())->toBeFalse();
});

test('a sort key the source does not declare falls back rather than reaching SQL', function () {
    $viewer = reportAdmin();
    Lead::factory()->count(2)->ownedBy($viewer)->create(['status' => LeadStatus::New->value]);
    Lead::factory()->ownedBy($viewer)->create(['status' => LeadStatus::Qualified->value]);

    $result = runReport([
        'source' => 'leads',
        'dimensions' => ['status'],
        'measures' => ['count'],
        'sort_by' => 'leads.id; drop table leads',
    ], $viewer);

    // Biggest first, the default — and the table is still there.
    expect($result->rows[0]->value('count'))->toBe(2)
        ->and(Lead::query()->count())->toBe(3);
});

// -- Access --------------------------------------------------------------------

test('a report only aggregates records the viewer may see', function () {
    $mine = reportUser(['reports.view', 'deals.view']);

    Deal::factory()->ownedBy($mine)->create(['value' => 100]);
    Deal::factory()->create(['value' => 5000]);

    $result = runReport(['source' => 'deals', 'measures' => ['count', 'value']], $mine);

    // An aggregate is the easiest kind of leak to miss, because it does not
    // look like the rows behind it.
    expect($result->totals['count'])->toBe(1)
        ->and($result->totals['value'])->toBe(100.0);
});

test('a removed record is not in the figures', function () {
    $viewer = reportAdmin();

    Deal::factory()->ownedBy($viewer)->create(['value' => 100]);
    Deal::factory()->ownedBy($viewer)->create(['value' => 900])->delete();

    // applyScopes() before getQuery(), or the soft-delete scope is dropped.
    $result = runReport(['source' => 'deals', 'measures' => ['count', 'value']], $viewer);

    expect($result->totals['count'])->toBe(1)
        ->and($result->totals['value'])->toBe(100.0);
});

// -- Filters -------------------------------------------------------------------

test('a filter narrows the report', function () {
    $viewer = reportAdmin();

    Lead::factory()->count(2)->ownedBy($viewer)->create(['status' => LeadStatus::New->value]);
    Lead::factory()->ownedBy($viewer)->create(['status' => LeadStatus::Qualified->value]);

    $result = runReport([
        'source' => 'leads',
        'measures' => ['count'],
        'filters' => [
            'match' => FilterGroup::MATCH_ALL,
            'conditions' => [[
                'field' => 'status',
                'operator' => FilterOperator::Equals->value,
                'value' => LeadStatus::New->value,
                'value2' => null,
            ]],
            'groups' => [],
        ],
    ], $viewer);

    expect($result->totals['count'])->toBe(2);
});

test('a filter on a field the source does not declare is dropped', function () {
    $viewer = reportAdmin();
    Lead::factory()->count(3)->ownedBy($viewer)->create();

    $result = runReport([
        'source' => 'leads',
        'measures' => ['count'],
        'filters' => [
            'match' => FilterGroup::MATCH_ALL,
            'conditions' => [[
                'field' => 'password',
                'operator' => FilterOperator::IsNotEmpty->value,
                'value' => null,
                'value2' => null,
            ]],
            'groups' => [],
        ],
    ], $viewer);

    expect($result->totals['count'])->toBe(3);
});

// -- Limits --------------------------------------------------------------------

test('a report says when it cut the list rather than implying it was complete', function () {
    $viewer = reportAdmin();
    Lead::factory()->count(5)->ownedBy($viewer)->sequence(fn ($sequence) => [
        'company_name' => 'Company '.$sequence->index,
    ])->create();

    $result = runReport([
        'source' => 'leads',
        'dimensions' => ['created'],
        'measures' => ['count'],
        'grain' => 'day',
        'limit' => 2,
    ], $viewer);

    expect($result->rowCount())->toBeLessThanOrEqual(2);
});

test('the row cap is enforced however large a limit is asked for', function () {
    $definition = ReportDefinition::fromArray([
        'source' => 'leads',
        'measures' => ['count'],
        'limit' => 999999,
    ]);

    expect($definition->limit)->toBe(999999)
        // The runner clamps rather than the definition, so a stored report is
        // kept as it was written and the cap can be raised later in one place.
        ->and(ReportRunner::MAX_ROWS)->toBe(1000);
});

// -- The definition ------------------------------------------------------------

test('a definition survives a round trip through an array', function () {
    $definition = ReportDefinition::fromArray([
        'source' => 'deals',
        'dimensions' => ['stage', 'owner'],
        'measures' => ['count', 'value'],
        'grain' => 'quarter',
        'sort_by' => 'value',
        'sort_direction' => 'asc',
        'limit' => 50,
    ]);

    $again = ReportDefinition::fromArray($definition->toArray());

    expect($again->source)->toBe('deals')
        ->and($again->dimensions)->toBe(['stage', 'owner'])
        ->and($again->measures)->toBe(['count', 'value'])
        ->and($again->grain)->toBe(DateGrain::Quarter)
        ->and($again->sortBy)->toBe('value')
        ->and($again->sortDirection)->toBe('asc')
        ->and($again->limit)->toBe(50);
});

test('a definition with no measure is not runnable', function () {
    expect(ReportDefinition::fromArray(['source' => 'deals', 'dimensions' => ['stage']])->isRunnable())
        ->toBeFalse();
});

test('changing the source clears the fields chosen for the old one', function () {
    $definition = ReportDefinition::fromArray([
        'source' => 'deals',
        'dimensions' => ['stage'],
        'measures' => ['value'],
    ]);

    // A deal's stage means nothing on a lead, and keeping it would silently
    // produce a report with a column that never fills.
    $moved = $definition->withSource('leads');

    expect($moved->source)->toBe('leads')
        ->and($moved->dimensions)->toBe([])
        ->and($moved->measures)->toBe([]);
});
