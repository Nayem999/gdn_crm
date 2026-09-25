<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Activities\Enums\ActivityType;
use App\Domain\Activities\Models\Activity;
use App\Domain\Deals\Enums\DealCloseReason;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Reports\Models\Report;
use App\Domain\Reports\ReportResult;
use App\Domain\Reports\ReportRunner;
use App\Domain\Reports\ReportSources;
use App\Domain\Reports\StandardReports;
use App\Domain\Sales\Models\Quote;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * One built-in, run for a viewer.
 */
function standardReport(string $slug, User $viewer): ReportResult
{
    $spec = StandardReports::find($slug);

    expect($spec)->not->toBeNull();

    return app(ReportRunner::class)->run($spec['definition'], $viewer);
}

/**
 * The rows keyed by their first dimension, for an assertion that reads.
 *
 * @return array<string, array<string, float|int|null>>
 */
function standardRows(ReportResult $result): array
{
    $keyed = [];

    foreach ($result->rows as $row) {
        $keyed[$row->label()] = $row->values;
    }

    return $keyed;
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-30 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

// -- The catalogue -------------------------------------------------------------

test('the twelve standard reports are declared', function () {
    // Phase 12 added three: leads by Meta campaign, revenue by Meta campaign,
    // and what became of the leads the advertising brought in. Then the win
    // and loss reasons the user guide had long promised.
    expect(StandardReports::SLUGS)->toHaveCount(12)
        ->and(array_keys(StandardReports::all()))->toBe(StandardReports::SLUGS);
});

test('every standard report names a source and fields that exist', function (string $slug) {
    $spec = StandardReports::find($slug);
    $definition = $spec['definition'];
    $source = ReportSources::find($definition->source);

    expect($source)->not->toBeNull()
        ->and($definition->measures)->not->toBeEmpty();

    // A built-in naming a field the registry does not declare would silently
    // lose a column — the runner drops what it does not recognise.
    foreach ($definition->dimensions as $key) {
        expect($source->dimension($key))->not->toBeNull();
    }

    foreach ($definition->measures as $key) {
        expect($source->measure($key))->not->toBeNull();
    }
})->with(StandardReports::SLUGS);

test('installing is idempotent and never overwrites', function () {
    expect(StandardReports::install())->toBe(12)
        ->and(StandardReports::install())->toBe(0);

    $report = Report::query()->where('slug', 'top-customers')->firstOrFail();
    $report->forceFill(['name' => 'Our best accounts'])->save();

    StandardReports::install();

    // A company may have edited a built-in to suit itself, and a seeder that
    // undid that on every deploy is one nobody dares run.
    expect($report->fresh()->name)->toBe('Our best accounts');
});

test('the built-ins are shared, standard and owned by nobody', function () {
    StandardReports::install();

    foreach (Report::query()->where('is_standard', true)->get() as $report) {
        expect($report->is_shared)->toBeTrue()
            ->and($report->owner_id)->toBeNull()
            ->and($report->slug)->not->toBeNull();
    }
});

// -- Each report against seeded data -------------------------------------------

test('sales by month totals the won deals, month by month', function () {
    $viewer = reportAdmin();

    Deal::factory()->ownedBy($viewer)->create([
        'stage' => DealStage::Won->value, 'value' => 1000, 'closed_at' => '2026-08-10 09:00:00',
    ]);
    Deal::factory()->ownedBy($viewer)->create([
        'stage' => DealStage::Won->value, 'value' => 500, 'closed_at' => '2026-08-20 09:00:00',
    ]);
    Deal::factory()->ownedBy($viewer)->create([
        'stage' => DealStage::Won->value, 'value' => 2000, 'closed_at' => '2026-09-05 09:00:00',
    ]);
    // Lost, and must not be in a figure called "sales".
    Deal::factory()->ownedBy($viewer)->create([
        'stage' => DealStage::Lost->value, 'value' => 9999, 'closed_at' => '2026-09-06 09:00:00',
    ]);

    $rows = standardRows(standardReport('sales-by-month', $viewer));

    expect($rows['2026-08']['value'])->toBe(1500.0)
        ->and($rows['2026-08']['count'])->toBe(2)
        ->and($rows['2026-09']['value'])->toBe(2000.0)
        ->and($rows)->not->toHaveKey('(none)');
});

test('leads by source counts and values each source', function () {
    $viewer = reportAdmin();

    Lead::factory()->count(2)->ownedBy($viewer)->create([
        'source' => LeadSource::WebForm->value, 'estimated_value' => 100,
    ]);
    Lead::factory()->ownedBy($viewer)->create([
        'source' => LeadSource::Referral->value, 'estimated_value' => 900,
    ]);

    $rows = standardRows(standardReport('leads-by-source', $viewer));

    expect($rows[LeadSource::WebForm->label()]['count'])->toBe(2)
        ->and($rows[LeadSource::WebForm->label()]['estimated_value'])->toBe(200.0)
        ->and($rows[LeadSource::Referral->label()]['count'])->toBe(1);
});

test('pipeline by stage counts what is in play', function () {
    $viewer = reportAdmin();

    Deal::factory()->count(3)->ownedBy($viewer)->create(['stage' => 'qualification', 'value' => 100]);
    Deal::factory()->ownedBy($viewer)->create(['stage' => 'proposal', 'value' => 700]);
    // Already decided, so not in play: neither may reach the funnel.
    Deal::factory()->ownedBy($viewer)->create(['stage' => DealStage::Won->value, 'value' => 9000]);
    Deal::factory()->ownedBy($viewer)->create(['stage' => DealStage::Lost->value, 'value' => 9000]);

    $result = standardReport('pipeline-by-stage', $viewer);
    $rows = standardRows($result);

    // Biggest first, because that is the sort the built-in asks for.
    expect($result->rows[0]->value('count'))->toBe(3)
        ->and(array_sum(array_column($rows, 'value')))->toBe(1000.0);
});

test('lead conversion shows arrivals and conversions side by side', function () {
    $viewer = reportAdmin();

    Lead::factory()->count(3)->ownedBy($viewer)->create([
        'status' => LeadStatus::Qualified->value, 'converted_at' => null,
    ]);
    Lead::factory()->count(2)->ownedBy($viewer)->create([
        'status' => LeadStatus::Qualified->value, 'converted_at' => '2026-09-01 09:00:00',
    ]);

    $rows = standardRows(standardReport('lead-conversion', $viewer));

    // A conversion rate reported as one number hides whether it moved because
    // more converted or because fewer arrived.
    expect($rows[LeadStatus::Qualified->label()]['count'])->toBe(5)
        ->and($rows[LeadStatus::Qualified->label()]['converted'])->toBe(2);
});

test('revenue by month counts accepted quotes only', function () {
    $viewer = reportAdmin();

    Quote::factory()->ownedBy($viewer)->create([
        'total' => 1200, 'accepted_at' => '2026-09-10 09:00:00',
    ]);
    Quote::factory()->ownedBy($viewer)->create([
        'total' => 800, 'accepted_at' => '2026-09-20 09:00:00',
    ]);
    Quote::factory()->ownedBy($viewer)->create(['total' => 5000, 'accepted_at' => null]);

    $rows = standardRows(standardReport('revenue-by-month', $viewer));

    expect($rows['2026-09']['total'])->toBe(2000.0)
        ->and($rows['2026-09']['count'])->toBe(2);
});

test('salesperson performance reports what each person won and lost', function () {
    $dana = reportAdmin();
    $sam = reportAdmin();

    Deal::factory()->count(2)->ownedBy($dana)->create(['stage' => DealStage::Won->value, 'value' => 500]);
    Deal::factory()->ownedBy($dana)->create(['stage' => DealStage::Lost->value, 'value' => 5000]);
    // Open: counts toward neither side, and not toward the win rate.
    Deal::factory()->ownedBy($dana)->create(['stage' => 'proposal', 'value' => 7000]);
    Deal::factory()->ownedBy($sam)->create(['stage' => DealStage::Won->value, 'value' => 100]);

    $result = standardReport('salesperson-performance', $dana);
    $rows = standardRows($result);

    expect($rows[$dana->name]['won_value'])->toBe(1000.0)
        ->and($rows[$dana->name]['won_count'])->toBe(2)
        ->and($rows[$dana->name]['lost_count'])->toBe(1)
        // Two won of three decided; the open one is left out, not counted lost.
        ->and(round((float) $rows[$dana->name]['win_rate'], 1))->toBe(66.7)
        ->and($rows[$sam->name]['win_rate'])->toBe(100.0)
        // Ranked by what was won — the lost 5000 does not put anybody higher.
        ->and($result->rows[0]->label())->toBe($dana->name);
});

test('activity by person groups by owner and type', function () {
    $viewer = reportAdmin();

    Activity::factory()->count(2)->ownedBy($viewer)->call()->create();
    Activity::factory()->ownedBy($viewer)->meeting()->create();

    $rows = standardRows(standardReport('activity-by-person', $viewer));

    expect($rows[$viewer->name.' — '.ActivityType::Call->label()]['count'])->toBe(2)
        ->and($rows[$viewer->name.' — '.ActivityType::Meeting->label()]['count'])->toBe(1);
});

test('top customers ranks accounts by what they have won', function () {
    $viewer = reportAdmin();

    $big = Account::factory()->create(['name' => 'Big Co']);
    $small = Account::factory()->create(['name' => 'Small Co']);
    $tyre = Account::factory()->create(['name' => 'Tyre Kicker Ltd']);

    Deal::factory()->ownedBy($viewer)->create(['account_id' => $big->id, 'value' => 5000, 'stage' => DealStage::Won->value]);
    Deal::factory()->ownedBy($viewer)->create(['account_id' => $big->id, 'value' => 1000, 'stage' => DealStage::Won->value]);
    Deal::factory()->ownedBy($viewer)->create(['account_id' => $small->id, 'value' => 200, 'stage' => DealStage::Won->value]);
    // Lost and open deals are not purchases; this account bought nothing.
    Deal::factory()->ownedBy($viewer)->create(['account_id' => $tyre->id, 'value' => 90000, 'stage' => DealStage::Lost->value]);
    Deal::factory()->ownedBy($viewer)->create(['account_id' => $small->id, 'value' => 90000, 'stage' => 'proposal']);

    $result = standardReport('top-customers', $viewer);

    expect($result->rows)->toHaveCount(2)
        ->and($result->rows[0]->label())->toBe('Big Co')
        ->and($result->rows[0]->value('value'))->toBe(6000.0)
        ->and($result->rows[0]->id('account'))->toBe($big->id)
        ->and($result->rows[1]->label())->toBe('Small Co')
        ->and($result->rows[1]->value('value'))->toBe(200.0);
});

test('two accounts with the same name are two customers', function () {
    $viewer = reportAdmin();

    $north = Account::factory()->create(['name' => 'Acme']);
    $south = Account::factory()->create(['name' => 'Acme']);

    Deal::factory()->ownedBy($viewer)->create(['account_id' => $north->id, 'value' => 300, 'stage' => DealStage::Won->value]);
    Deal::factory()->ownedBy($viewer)->create(['account_id' => $south->id, 'value' => 700, 'stage' => DealStage::Won->value]);

    $result = standardReport('top-customers', $viewer);

    expect($result->rows)->toHaveCount(2)
        ->and(array_map(fn ($row) => $row->id('account'), $result->rows))->toBe([$south->id, $north->id]);
});

test('win and loss reasons split the closed deals by why', function () {
    $viewer = reportAdmin();

    Deal::factory()->ownedBy($viewer)->create(['stage' => DealStage::Won->value, 'close_reason' => 'best_fit', 'value' => 800]);
    Deal::factory()->count(2)->ownedBy($viewer)->create(['stage' => DealStage::Lost->value, 'close_reason' => 'lost_on_price', 'value' => 100]);
    Deal::factory()->ownedBy($viewer)->create(['stage' => 'proposal', 'close_reason' => null, 'value' => 5000]);

    $rows = standardRows(standardReport('win-loss-reasons', $viewer));

    expect($rows)->not->toHaveKey('(none)')
        ->and($rows[DealCloseReason::LostOnPrice->label()]['lost_count'])->toBe(2)
        ->and($rows[DealCloseReason::BestFit->label()]['won_value'])->toBe(800.0);
});

test('an untouched built-in is upgraded and an edited one is left alone', function () {
    StandardReports::install();

    $untouched = Report::query()->where('slug', 'salesperson-performance')->firstOrFail();
    $untouched->forceFill(['definition' => StandardReports::previousDefinitions()['salesperson-performance']])->save();

    $edited = Report::query()->where('slug', 'top-customers')->firstOrFail();
    $mine = [...StandardReports::previousDefinitions()['top-customers'], 'limit' => 5];
    $edited->forceFill(['definition' => $mine])->save();

    expect(StandardReports::upgrade())->toBe(1)
        ->and($untouched->fresh()->definition()->measures)->toContain('won_value')
        ->and($edited->fresh()->definition()->limit)->toBe(5)
        ->and($edited->fresh()->definition()->measures)->toBe(['value', 'count']);
});

// -- Access --------------------------------------------------------------------

test('a standard report is still scoped to the viewer', function () {
    $mine = reportUser(['reports.view', 'deals.view']);

    Deal::factory()->ownedBy($mine)->create(['value' => 100, 'stage' => 'qualification']);
    Deal::factory()->create(['value' => 9000, 'stage' => 'qualification']);

    $result = standardReport('pipeline-by-stage', $mine);

    // A built-in goes through the same runner with the same scoping — that is
    // the whole reason they are definitions rather than hand-written SQL.
    expect($result->totals['value'])->toBe(100.0);
});

test('a standard report about a module the viewer cannot see is refused', function () {
    $agent = reportUser(['reports.view']);

    expect(standardReport('pipeline-by-stage', $agent)->refused)->toBeTrue();
});

test('every standard report runs without error for somebody who can see everything', function (string $slug) {
    $viewer = reportAdmin();

    $result = standardReport($slug, $viewer);

    // Empty is fine; refused or broken is not.
    expect($result->refused)->toBeFalse()
        ->and($result->isRunnable())->toBeTrue();
})->with(StandardReports::SLUGS);
