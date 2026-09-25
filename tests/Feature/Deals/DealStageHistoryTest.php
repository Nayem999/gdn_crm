<?php

use App\Domain\Deals\Actions\CloseDealAction;
use App\Domain\Deals\Actions\MoveDealStageAction;
use App\Domain\Deals\Actions\UpdateDealAction;
use App\Domain\Deals\Enums\DealCloseReason;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\DealStageEntry;
use App\Domain\Leads\Actions\ConvertLeadAction;
use App\Domain\Leads\DTOs\LeadConversionData;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Livewire\Deals\DealShow;
use App\Livewire\Deals\DealsIndex;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

afterEach(function () {
    Carbon::setTestNow();
});

function move(Deal $deal, string $stageKey): void
{
    app(MoveDealStageAction::class)($deal->fresh(), $stageKey);
}

/**
 * @return array<int, DealStageEntry>
 */
function history(Deal $deal): array
{
    return $deal->stageEntries()->get()->all();
}

// -- An entry per stage change -------------------------------------------------

test('a new deal opens its first visit at the stage it starts in', function () {
    $pipeline = dealPipeline();

    Carbon::setTestNow('2026-05-01 09:00:00');
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    $entries = history($deal);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->stage_key)->toBe('scoping')
        ->and($entries[0]->stage_name)->toBe('Scoping')
        ->and($entries[0]->outcome())->toBe(StageOutcome::Open)
        ->and($entries[0]->entered_at->toDateTimeString())->toBe('2026-05-01 09:00:00')
        ->and($entries[0]->left_at)->toBeNull()
        ->and($entries[0]->isOpen())->toBeTrue();
});

test('every stage change closes the visit before it and opens the next', function () {
    $pipeline = dealPipeline();

    Carbon::setTestNow('2026-05-01 09:00:00');
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    Carbon::setTestNow('2026-05-04 09:00:00');
    move($deal, 'negotiation');

    Carbon::setTestNow('2026-05-09 09:00:00');
    move($deal, 'closed_won');

    $entries = history($deal);

    expect($entries)->toHaveCount(3)
        ->and(array_map(fn (DealStageEntry $e) => $e->stage_key, $entries))
        ->toBe(['scoping', 'negotiation', 'closed_won']);

    // No gap and no overlap: each visit ends exactly where the next begins.
    expect($entries[0]->left_at->toDateTimeString())->toBe('2026-05-04 09:00:00')
        ->and($entries[1]->entered_at->toDateTimeString())->toBe('2026-05-04 09:00:00')
        ->and($entries[1]->left_at->toDateTimeString())->toBe('2026-05-09 09:00:00')
        ->and($entries[2]->entered_at->toDateTimeString())->toBe('2026-05-09 09:00:00')
        ->and($entries[2]->left_at)->toBeNull();
});

test('a closed visit stores the duration its own timestamps imply', function () {
    $pipeline = dealPipeline();

    Carbon::setTestNow('2026-05-01 09:00:00');
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    Carbon::setTestNow('2026-05-04 09:00:00');
    move($deal, 'negotiation');

    $first = history($deal)[0];

    expect($first->duration_seconds)->toBe(3 * 86400)
        ->and($first->seconds())->toBe(3 * 86400)
        ->and($first->forHumans())->toBe('3 days');
});

test('exactly one visit is ever open', function () {
    $pipeline = dealPipeline();
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    foreach (['negotiation', 'scoping', 'negotiation', 'closed_lost'] as $stage) {
        move($deal, $stage);

        expect($deal->stageEntries()->stillOpen()->count())->toBe(1);
    }

    expect(history($deal))->toHaveCount(5);
});

test('a move that is refused records nothing', function () {
    $pipeline = dealPipeline();
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    expect(fn () => move($deal, 'renewal_due'))->toThrow(RuntimeException::class);

    expect(history($deal))->toHaveCount(1)
        ->and(history($deal)[0]->isOpen())->toBeTrue();
});

test('dropping a deal back into the stage it is already in records no new visit', function () {
    $pipeline = dealPipeline();

    Carbon::setTestNow('2026-05-01 09:00:00');
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    Carbon::setTestNow('2026-05-05 09:00:00');
    move($deal, 'scoping');

    // A second visit here would reset the clock on a stage the deal never left.
    $entries = history($deal);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->entered_at->toDateTimeString())->toBe('2026-05-01 09:00:00')
        ->and($entries[0]->isOpen())->toBeTrue();
});

test('an edit that changes no stage records nothing', function () {
    $pipeline = dealPipeline();
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    app(UpdateDealAction::class)($deal, dealData([
        'account_id' => $deal->account_id,
        'name' => 'Renamed',
        'value' => '9000',
    ]));

    expect(history($deal))->toHaveCount(1);
});

// -- Revisiting a stage --------------------------------------------------------

test('coming back to a stage is a second visit with its own duration', function () {
    $pipeline = dealPipeline();

    Carbon::setTestNow('2026-05-01 09:00:00');
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    Carbon::setTestNow('2026-05-03 09:00:00');
    move($deal, 'negotiation');

    Carbon::setTestNow('2026-05-06 09:00:00');
    move($deal, 'scoping');

    Carbon::setTestNow('2026-05-10 09:00:00');
    move($deal, 'negotiation');

    $visits = collect(history($deal))->where('stage_key', 'scoping')->values();

    expect($visits)->toHaveCount(2)
        ->and($visits[0]->duration_seconds)->toBe(2 * 86400)
        ->and($visits[1]->duration_seconds)->toBe(4 * 86400);
});

test('time per stage sums the repeat visits', function () {
    $pipeline = dealPipeline();

    Carbon::setTestNow('2026-05-01 09:00:00');
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    Carbon::setTestNow('2026-05-03 09:00:00');
    move($deal, 'negotiation');

    Carbon::setTestNow('2026-05-06 09:00:00');
    move($deal, 'scoping');

    Carbon::setTestNow('2026-05-10 09:00:00');
    move($deal, 'closed_won');

    $totals = $deal->fresh()->timePerStage();

    // Two days, then four more: what somebody asks is how long it spent in
    // Scoping altogether.
    expect($totals['scoping']['seconds'])->toBe(6 * 86400)
        ->and($totals['scoping']['visits'])->toBe(2)
        ->and($totals['negotiation']['seconds'])->toBe(3 * 86400)
        ->and($totals['negotiation']['visits'])->toBe(1);
});

// -- The open visit counts up --------------------------------------------------

test('the open visit measures up to now, and the current stage with it', function () {
    $pipeline = dealPipeline();

    Carbon::setTestNow('2026-05-01 09:00:00');
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    Carbon::setTestNow('2026-05-08 09:00:00');

    expect($deal->fresh()->currentStageEntry()->seconds())->toBe(7 * 86400)
        ->and($deal->fresh()->secondsInCurrentStage())->toBe(7 * 86400)
        ->and($deal->fresh()->currentStageEntry()->forHumans())->toBe('7 days');
});

test('the duration reads in the unit a person would use', function (int $seconds, string $expected) {
    $entry = DealStageEntry::factory()->lasting(
        Carbon::parse('2026-05-01 09:00:00'),
        Carbon::parse('2026-05-01 09:00:00')->addSeconds($seconds),
    )->create();

    expect($entry->forHumans())->toBe($expected);
})->with([
    [30, 'under a minute'],
    [600, '10 min'],
    [7200, '2 hr'],
    [86400, '1 day'],
    [86400 * 5, '5 days'],
]);

// -- Cycle time ----------------------------------------------------------------

test('cycle time runs from creation to the close, and to now while open', function () {
    $pipeline = dealPipeline();

    Carbon::setTestNow('2026-05-01 09:00:00');
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    Carbon::setTestNow('2026-05-06 09:00:00');
    expect($deal->fresh()->cycleSeconds())->toBe(5 * 86400);

    Carbon::setTestNow('2026-05-11 09:00:00');
    app(CloseDealAction::class)($deal->fresh(), 'closed_won', DealCloseReason::BestFit);

    // Frozen at the close: the clock stops when the deal does.
    Carbon::setTestNow('2026-06-01 09:00:00');
    expect($deal->fresh()->cycleSeconds())->toBe(10 * 86400);
});

// -- What the snapshot protects ------------------------------------------------

test('renaming a stage leaves the visits that happened under the old name alone', function () {
    $pipeline = dealPipeline();

    Carbon::setTestNow('2026-05-01 09:00:00');
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    Carbon::setTestNow('2026-05-03 09:00:00');
    move($deal, 'negotiation');

    savePipeline(pipelineData('Sales', [
        ['name' => 'Discovery', 'outcome' => 'open', 'probability' => 20, 'color' => 'blue', 'key' => 'scoping'],
        ['name' => 'Negotiation', 'outcome' => 'open', 'probability' => 60, 'color' => 'amber', 'key' => 'negotiation'],
        ['name' => 'Closed won', 'outcome' => 'won', 'probability' => 100, 'color' => 'emerald', 'key' => 'closed_won'],
        ['name' => 'Closed lost', 'outcome' => 'lost', 'probability' => 0, 'color' => 'rose', 'key' => 'closed_lost'],
    ], isDefault: true), $pipeline);

    // History that changes retrospectively is not history: the visit happened
    // while the stage was called Scoping.
    expect(history($deal)[0]->stage_name)->toBe('Scoping')
        ->and($deal->fresh()->configuredStage()->name)->toBe('Negotiation');
});

test('a visit survives its stage being removed from the pipeline', function () {
    $pipeline = dealPipeline();
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));
    move($deal, 'negotiation');

    // Nothing sits in Scoping now, so it can go.
    savePipeline(pipelineData('Sales', [
        ['name' => 'Negotiation', 'outcome' => 'open', 'probability' => 60, 'color' => 'amber', 'key' => 'negotiation'],
        ['name' => 'Closed won', 'outcome' => 'won', 'probability' => 100, 'color' => 'emerald', 'key' => 'closed_won'],
        ['name' => 'Closed lost', 'outcome' => 'lost', 'probability' => 0, 'color' => 'rose', 'key' => 'closed_lost'],
    ], isDefault: true), $pipeline);

    expect($pipeline->fresh()->stageByKey('scoping'))->toBeNull()
        ->and(history($deal)[0]->stage_name)->toBe('Scoping')
        ->and(history($deal)[0]->duration_seconds)->not->toBeNull();
});

test('a stage key matching no configured stage still records a visit', function () {
    // What a deal created before pipelines existed looks like.
    $deal = Deal::factory()->create(['pipeline_id' => null, 'stage' => DealStage::New->value]);

    expect(history($deal))->toHaveCount(1)
        ->and(history($deal)[0]->stage_key)->toBe('new');
});

// -- Who moved it --------------------------------------------------------------

test('a visit records who opened it, and nobody when there is nobody', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    // Created outside a request, so there is nobody signed in.
    expect(history($deal)[0]->moved_by_id)->toBeNull();

    Livewire::actingAs($user)->test(DealsIndex::class)->call('moveCard', $deal->id, 'negotiation');

    $entries = history($deal->fresh());

    expect(end($entries)->moved_by_id)->toBe($user->id);
});

// -- It follows the deal, not the caller ---------------------------------------

test('history is recorded however the stage was changed', function (string $how) {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    match ($how) {
        'action' => app(MoveDealStageAction::class)($deal, 'negotiation'),
        'board' => Livewire::actingAs($user)->test(DealsIndex::class)
            ->call('moveCard', $deal->id, 'negotiation') && true,
        'detail page' => Livewire::actingAs($user)->test(DealShow::class, ['deal' => $deal])
            ->call('moveTo', 'negotiation') && true,
        // The trait hooks model events, so even a write that bypasses every
        // action is recorded — which is the point of not hooking the actions.
        'raw write' => $deal->forceFill(['stage' => 'negotiation'])->save(),
    };

    $entries = history($deal->fresh());

    expect($entries)->toHaveCount(2)
        ->and(end($entries)->stage_key)->toBe('negotiation')
        ->and($entries[0]->left_at)->not->toBeNull();
})->with(['action', 'board', 'detail page', 'raw write']);

test('lead conversion opens the deal history too', function () {
    $pipeline = dealPipeline();

    $lead = Lead::factory()->create([
        'status' => LeadStatus::Qualified->value,
    ]);

    $result = app(ConvertLeadAction::class)(
        $lead,
        new LeadConversionData(createDeal: true),
        $lead->primaryAssignee(),
    );

    // Conversion sets the stage with forceFill after create, so the trait sees
    // both the insert and the change — and must not leave two open visits.
    expect($result->deal->stageEntries()->stillOpen()->count())->toBe(1)
        ->and($result->deal->currentStageEntry()->stage_key)->toBe('scoping');
});

// -- Deleting ------------------------------------------------------------------

test('soft deleting a deal keeps its history, and forcing it removes it', function () {
    $pipeline = dealPipeline();
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));
    move($deal, 'negotiation');

    $deal->fresh()->delete();

    expect(DealStageEntry::query()->where('deal_id', $deal->id)->count())->toBe(2);

    Deal::withTrashed()->whereKey($deal->id)->first()->forceDelete();

    // The foreign key cascades: history for a row that is truly gone is
    // unreachable rather than orphaned.
    expect(DealStageEntry::query()->where('deal_id', $deal->id)->count())->toBe(0);
});

// -- The screen ----------------------------------------------------------------

test('the deal page shows the stage history with each duration', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();

    Carbon::setTestNow('2026-05-01 09:00:00');
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    Carbon::setTestNow('2026-05-04 09:00:00');
    move($deal, 'negotiation');

    Carbon::setTestNow('2026-05-06 09:00:00');

    Livewire::actingAs($user)
        ->test(DealShow::class, ['deal' => $deal->fresh()])
        ->assertSuccessful()
        ->assertSee('Stage history')
        ->assertSeeInOrder(['Scoping', '3 days', 'Negotiation', '2 days'])
        // Creation to now while it is open.
        ->assertSee('Open for')
        ->assertSee('5 days');
});

test('the page names the total per stage only once a stage was revisited', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    // One visit each, so a totals block would just repeat the list above it.
    move($deal, 'negotiation');

    Livewire::actingAs($user)
        ->test(DealShow::class, ['deal' => $deal->fresh()])
        ->assertDontSee('Total per stage');

    move($deal, 'scoping');

    Livewire::actingAs($user)
        ->test(DealShow::class, ['deal' => $deal->fresh()])
        ->assertSee('Total per stage');
});

test('a deal with nothing recorded says so rather than showing a blank panel', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    $deal->stageEntries()->delete();

    Livewire::actingAs($user)
        ->test(DealShow::class, ['deal' => $deal->fresh()])
        ->assertSee('Nothing recorded yet');
});

test('the history panel is not a way past the deal policy', function () {
    $pipeline = dealPipeline();

    $owner = dealUser(['deals.view']);
    $peer = dealUser(['deals.view']);
    $deal = Deal::factory()->ownedBy($owner)->onPipeline($pipeline)->create();

    Livewire::actingAs($peer)->test(DealShow::class, ['deal' => $deal])->assertForbidden();
});

// -- Nothing edits a visit after the fact --------------------------------------

test('closing a visit does not move when it started', function () {
    $entry = DealStageEntry::factory()->create([
        'entered_at' => Carbon::parse('2026-05-01 09:00:00'),
    ]);

    Carbon::setTestNow('2026-05-04 09:00:00');
    $entry->forceFill(['left_at' => now(), 'duration_seconds' => 3 * 86400])->save();

    // MySQL and MariaDB give the first NOT NULL TIMESTAMP column an implicit
    // ON UPDATE CURRENT_TIMESTAMP, which rewrote entered_at on every close and
    // made every duration wrong with nothing in the logs. entered_at is a
    // DATETIME so that cannot happen — this pins the column type.
    expect($entry->fresh()->entered_at->toDateTimeString())->toBe('2026-05-01 09:00:00');

    $columns = DB::select('SHOW COLUMNS FROM deal_stage_entries WHERE Field = ?', ['entered_at']);

    expect($columns[0]->Type)->toBe('datetime')
        ->and($columns[0]->Extra)->toBe('');
});

test('an entry carries no timestamps of its own to disagree with', function () {
    $entry = DealStageEntry::factory()->create();

    // entered_at *is* when the row was created; a created_at beside it would be
    // a second answer to the same question.
    expect($entry->timestamps)->toBeFalse()
        ->and(Schema::hasColumn('deal_stage_entries', 'created_at'))->toBeFalse();
});
