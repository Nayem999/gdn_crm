<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Actions\CloseDealAction;
use App\Domain\Deals\Actions\CreateDealAction;
use App\Domain\Deals\Actions\DeleteDealAction;
use App\Domain\Deals\Actions\MoveDealStageAction;
use App\Domain\Deals\Actions\UpdateDealAction;
use App\Domain\Deals\DTOs\DealData;
use App\Domain\Deals\Enums\DealCloseReason;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\Pipeline;
use App\Domain\Leads\Actions\ConvertLeadAction;
use App\Domain\Leads\DTOs\LeadConversionData;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Timeline\Models\Note;
use App\Models\User;
use Database\Seeders\PipelinesSeeder;

/**
 * A pipeline with a known shape: two open stages, then won and lost.
 */
function dealPipeline(string $name = 'Sales', bool $isDefault = true): Pipeline
{
    return savePipeline(pipelineData($name, [
        ['name' => 'Scoping', 'outcome' => 'open', 'probability' => 20, 'color' => 'blue'],
        ['name' => 'Negotiation', 'outcome' => 'open', 'probability' => 60, 'color' => 'amber'],
        ['name' => 'Closed won', 'outcome' => 'won', 'probability' => 100, 'color' => 'emerald'],
        ['name' => 'Closed lost', 'outcome' => 'lost', 'probability' => 0, 'color' => 'rose'],
    ], isDefault: $isDefault));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function dealData(array $overrides = []): DealData
{
    return DealData::fromArray([
        'name' => 'Acme rollout',
        'account_id' => $overrides['account_id'] ?? Account::factory()->create()->id,
        ...$overrides,
    ]);
}

function createDeal(DealData $data, ?User $actor = null): Deal
{
    return app(CreateDealAction::class)($data, $actor ?? User::factory()->create());
}

// -- Creating ------------------------------------------------------------------

test('a deal starts at the first open stage of its pipeline', function () {
    $pipeline = dealPipeline();

    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    expect($deal->stage)->toBe('scoping')
        ->and($deal->pipeline_id)->toBe($pipeline->id)
        ->and($deal->isOpen())->toBeTrue()
        ->and($deal->closed_at)->toBeNull();
});

test('a pipeline whose closing stage was dragged to the top still opens a deal open', function () {
    $pipeline = savePipeline(pipelineData('Backwards', [
        ['name' => 'Closed won', 'outcome' => 'won', 'probability' => 100, 'color' => 'emerald'],
        ['name' => 'Scoping', 'outcome' => 'open', 'probability' => 20, 'color' => 'blue'],
    ], isDefault: true));

    // The first stage closes, so taking it blindly would create deals that are
    // already won.
    expect(createDeal(dealData(['pipeline_id' => $pipeline->id]))->stage)->toBe('scoping');
});

test('a deal with no pipeline chosen lands on the default one', function () {
    $pipeline = dealPipeline();

    expect(createDeal(dealData())->pipeline_id)->toBe($pipeline->id);
});

test('a deal takes the creating user as owner when none was given', function () {
    dealPipeline();
    $actor = User::factory()->create();

    // An unassigned record is how visibility scoping springs a leak.
    expect(createDeal(dealData(), $actor)->owner_id)->toBe($actor->id);
});

test('a stage cannot be set through the form data', function () {
    dealPipeline();

    // DealData has no stage field at all, so a tampered payload carrying one
    // is simply not represented.
    $data = DealData::fromArray([
        'name' => 'Tampered',
        'account_id' => Account::factory()->create()->id,
        'stage' => 'closed_won',
    ]);

    expect(createDeal($data)->stage)->toBe('scoping');
});

test('a deal cannot be created against an account that does not exist', function () {
    dealPipeline();

    expect(fn () => createDeal(dealData(['account_id' => 999_999])))
        ->toThrow(RuntimeException::class, 'account does not exist');
});

test('a contact from another account is refused', function () {
    dealPipeline();
    $account = Account::factory()->create();
    $elsewhere = Contact::factory()->create(['account_id' => Account::factory()->create()->id]);

    expect(fn () => createDeal(dealData(['account_id' => $account->id, 'contact_id' => $elsewhere->id])))
        ->toThrow(RuntimeException::class, 'does not work at that account');
});

test('a contact at no account at all is allowed', function () {
    dealPipeline();
    $account = Account::factory()->create();
    $unattached = Contact::factory()->create(['account_id' => null]);

    expect(createDeal(dealData(['account_id' => $account->id, 'contact_id' => $unattached->id]))->contact_id)
        ->toBe($unattached->id);
});

test('a pipeline that does not exist is refused', function () {
    dealPipeline();

    expect(fn () => createDeal(dealData(['pipeline_id' => 999_999])))
        ->toThrow(RuntimeException::class, 'pipeline does not exist');
});

// -- Updating ------------------------------------------------------------------

test('an update changes the details and leaves the stage alone', function () {
    $pipeline = dealPipeline();
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));
    app(MoveDealStageAction::class)($deal, 'negotiation');

    app(UpdateDealAction::class)($deal, dealData([
        'account_id' => $deal->account_id,
        'name' => 'Acme rollout, phase two',
        'value' => '5000',
    ]));

    expect($deal->fresh()->name)->toBe('Acme rollout, phase two')
        ->and($deal->fresh()->stage)->toBe('negotiation');
});

test('a deal can only move to a pipeline that has the stage it sits in', function () {
    $sales = dealPipeline();
    $other = savePipeline(pipelineData('Renewals', [
        ['name' => 'Renewal due', 'outcome' => 'open', 'probability' => 50, 'color' => 'cyan'],
        ['name' => 'Closed won', 'outcome' => 'won', 'probability' => 100, 'color' => 'emerald'],
    ]));

    $deal = createDeal(dealData(['pipeline_id' => $sales->id]));

    // Guessing which Renewals stage corresponds to "Scoping" is a business
    // decision, and getting it wrong silently reprices the forecast.
    expect(fn () => app(UpdateDealAction::class)($deal, dealData([
        'account_id' => $deal->account_id,
        'pipeline_id' => $other->id,
    ])))->toThrow(RuntimeException::class, 'has no "scoping" stage');

    expect($deal->fresh()->pipeline_id)->toBe($sales->id);
});

test('a deal moves pipeline when the target has a matching stage key', function () {
    $sales = dealPipeline();
    $twin = savePipeline(pipelineData('Sales EMEA', [
        ['name' => 'Scoping', 'outcome' => 'open', 'probability' => 25, 'color' => 'blue'],
        ['name' => 'Closed won', 'outcome' => 'won', 'probability' => 100, 'color' => 'emerald'],
    ]));

    $deal = createDeal(dealData(['pipeline_id' => $sales->id]));

    app(UpdateDealAction::class)($deal, dealData([
        'account_id' => $deal->account_id,
        'pipeline_id' => $twin->id,
    ]));

    // The probability came with the new pipeline, which is the point of it.
    expect($deal->fresh()->pipeline_id)->toBe($twin->id)
        ->and($deal->fresh()->configuredStage()->probability)->toBe(25);
});

// -- Moving --------------------------------------------------------------------

test('a stage that is not on the pipeline is refused rather than written', function () {
    $pipeline = dealPipeline();
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    // The board would show the deal in no column at all, which is how a record
    // goes missing.
    expect(fn () => app(MoveDealStageAction::class)($deal, 'renewal_due'))
        ->toThrow(RuntimeException::class, 'not a stage on the Sales pipeline');

    expect($deal->fresh()->stage)->toBe('scoping');
});

test('reaching a closing stage stamps when it closed', function () {
    $pipeline = dealPipeline();
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    app(MoveDealStageAction::class)($deal, 'closed_won');

    expect($deal->fresh()->closed_at)->not->toBeNull()
        ->and($deal->fresh()->isWon())->toBeTrue()
        ->and($deal->fresh()->isOpen())->toBeFalse();
});

test('re-clicking the stage a deal is already in does not move the closing stamp', function () {
    $pipeline = dealPipeline();
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    app(MoveDealStageAction::class)($deal, 'closed_won');
    $stampedAt = $deal->fresh()->closed_at;

    Carbon\Carbon::setTestNow(now()->addHour());
    $moved = app(MoveDealStageAction::class)($deal->fresh(), 'closed_won');
    Carbon\Carbon::setTestNow();

    expect($moved)->toBeFalse()
        ->and($deal->fresh()->closed_at->toDateTimeString())->toBe($stampedAt->toDateTimeString());
});

test('moving back to an open stage clears the closing stamp and the reason', function () {
    $pipeline = dealPipeline();
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    app(CloseDealAction::class)($deal, 'closed_lost', DealCloseReason::LostOnPrice, 'Undercut');
    app(MoveDealStageAction::class)($deal->fresh(), 'negotiation');

    // A live deal that still says why it was lost reads as a closed one.
    $reopened = $deal->fresh();

    expect($reopened->closed_at)->toBeNull()
        ->and($reopened->close_reason)->toBeNull()
        ->and($reopened->close_notes)->toBeNull()
        ->and($reopened->isOpen())->toBeTrue();
});

test('a deal on no pipeline can still move between the enum stages', function () {
    // What a deal created before pipelines existed looks like.
    $deal = Deal::factory()->create(['pipeline_id' => null, 'stage' => DealStage::New->value]);

    app(MoveDealStageAction::class)($deal, DealStage::Proposal->value);

    expect($deal->fresh()->stage)->toBe('proposal');

    expect(fn () => app(MoveDealStageAction::class)($deal->fresh(), 'nonsense'))
        ->toThrow(RuntimeException::class, 'not a stage this deal can be in');
});

// -- Closing -------------------------------------------------------------------

test('closing records the stage, the reason and the notes together', function () {
    $pipeline = dealPipeline();
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    app(CloseDealAction::class)($deal, 'closed_won', DealCloseReason::BestFit, '  Beat two others  ');

    $closed = $deal->fresh();

    expect($closed->stage)->toBe('closed_won')
        ->and($closed->closeReason())->toBe(DealCloseReason::BestFit)
        ->and($closed->close_notes)->toBe('Beat two others')
        ->and($closed->closed_at)->not->toBeNull();
});

test('a reason from the wrong outcome is refused', function () {
    $pipeline = dealPipeline();
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    // Recording a won deal as "lost to a competitor" would corrupt the
    // win/loss report in a way nobody would spot until the quarter was over.
    expect(fn () => app(CloseDealAction::class)($deal, 'closed_won', DealCloseReason::LostToCompetitor))
        ->toThrow(RuntimeException::class, 'is a Lost reason, and this deal is Won');

    expect($deal->fresh()->close_reason)->toBeNull();
});

test('closing into a stage that does not close anything is refused', function () {
    $pipeline = dealPipeline();
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    expect(fn () => app(CloseDealAction::class)($deal, 'negotiation', DealCloseReason::BestFit))
        ->toThrow(RuntimeException::class, 'does not close a deal');
});

test('a deal already sitting in a closing stage still gets its reason recorded', function () {
    $pipeline = dealPipeline();
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));
    app(MoveDealStageAction::class)($deal, 'closed_lost');

    // The move is a no-op this time, so the stamp has to come from the close.
    app(CloseDealAction::class)($deal->fresh(), 'closed_lost', DealCloseReason::NoBudget);

    expect($deal->fresh()->closeReason())->toBe(DealCloseReason::NoBudget)
        ->and($deal->fresh()->closed_at)->not->toBeNull();
});

test('reopening into another closing stage is refused', function () {
    $pipeline = dealPipeline();
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));
    app(CloseDealAction::class)($deal, 'closed_won', DealCloseReason::BestFit);

    expect(fn () => app(CloseDealAction::class)->reopen($deal->fresh(), 'closed_lost'))
        ->toThrow(RuntimeException::class, 'still closed');
});

test('every close reason belongs to exactly one outcome', function () {
    foreach (DealCloseReason::cases() as $reason) {
        expect($reason->outcome()->isClosed())->toBeTrue()
            ->and($reason->isFor($reason->outcome()))->toBeTrue()
            ->and($reason->label())->not->toBeEmpty();
    }

    expect(DealCloseReason::forOutcome(StageOutcome::Won))->not->toBeEmpty()
        ->and(DealCloseReason::forOutcome(StageOutcome::Lost))->not->toBeEmpty()
        // No reason may appear under both, or the pairing check is meaningless.
        ->and(array_intersect(
            array_map(fn ($r) => $r->value, DealCloseReason::forOutcome(StageOutcome::Won)),
            array_map(fn ($r) => $r->value, DealCloseReason::forOutcome(StageOutcome::Lost)),
        ))->toBe([]);
});

// -- Weighted value ------------------------------------------------------------

test('the weighted value is the deal value at its stage probability', function (string $stageKey, int $probability) {
    $pipeline = dealPipeline();
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id, 'value' => '1000']));
    app(MoveDealStageAction::class)($deal, $stageKey);

    expect($deal->fresh()->weightedValue())->toBe(round(1000 * $probability / 100, 2));
})->with([
    ['scoping', 20],
    ['negotiation', 60],
    ['closed_won', 100],
    ['closed_lost', 0],
]);

test('changing the stage probability changes the forecast', function () {
    $pipeline = dealPipeline();
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id, 'value' => '1000']));

    expect($deal->weightedValue())->toBe(200.0);

    savePipeline(pipelineData('Sales', [
        ['name' => 'Scoping', 'outcome' => 'open', 'probability' => 45, 'color' => 'blue', 'key' => 'scoping'],
        ['name' => 'Negotiation', 'outcome' => 'open', 'probability' => 60, 'color' => 'amber', 'key' => 'negotiation'],
        ['name' => 'Closed won', 'outcome' => 'won', 'probability' => 100, 'color' => 'emerald', 'key' => 'closed_won'],
        ['name' => 'Closed lost', 'outcome' => 'lost', 'probability' => 0, 'color' => 'rose', 'key' => 'closed_lost'],
    ], isDefault: true), $pipeline);

    expect($deal->fresh()->weightedValue())->toBe(450.0);
});

test('a deal with no value is worth nothing weighted, not an error', function () {
    $pipeline = dealPipeline();
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id, 'value' => null]));

    expect($deal->value)->toBeNull()
        ->and($deal->weightedValue())->toBe(0.0);
});

test('a deal on no pipeline weights against the enum', function () {
    $deal = Deal::factory()->create([
        'pipeline_id' => null,
        'stage' => DealStage::Proposal->value,
        'value' => '2000',
    ]);

    // No pipelines at all, so the enum is the only thing to ask.
    expect(Pipeline::query()->count())->toBe(0)
        ->and($deal->weightedValue())->toBe(1000.0);
});

test('the seeded default pipeline reproduces the enum probabilities exactly', function (DealStage $stage) {
    (new PipelinesSeeder)->run();

    $deal = Deal::factory()->create([
        'pipeline_id' => Pipeline::default()->id,
        'stage' => $stage->value,
        'value' => '1000',
    ]);

    expect($deal->weightedValue())->toBe(round(1000 * $stage->probability() / 100, 2));
})->with(fn () => DealStage::pipeline());

// -- Outcome and scopes --------------------------------------------------------

test('the outcome is read from the stage, never from a column of its own', function () {
    $pipeline = dealPipeline();
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    expect($deal->outcome())->toBe(StageOutcome::Open);

    app(MoveDealStageAction::class)($deal, 'closed_won');
    expect($deal->fresh()->outcome())->toBe(StageOutcome::Won);

    app(MoveDealStageAction::class)($deal->fresh(), 'closed_lost');
    expect($deal->fresh()->outcome())->toBe(StageOutcome::Lost)
        ->and($deal->fresh()->isLost())->toBeTrue();
});

test('the open and closed scopes split the deals the way the outcomes do', function () {
    $pipeline = dealPipeline();

    $open = createDeal(dealData(['pipeline_id' => $pipeline->id]));
    $won = createDeal(dealData(['pipeline_id' => $pipeline->id]));
    $lost = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    app(MoveDealStageAction::class)($won, 'closed_won');
    app(MoveDealStageAction::class)($lost, 'closed_lost');

    expect(Deal::query()->open()->pluck('id')->all())->toBe([$open->id])
        ->and(Deal::query()->closed()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$won->id, $lost->id])->sort()->values()->all())
        ->and(Deal::query()->withOutcome(StageOutcome::Won)->pluck('id')->all())->toBe([$won->id])
        ->and(Deal::query()->withOutcome(StageOutcome::Lost)->pluck('id')->all())->toBe([$lost->id])
        ->and(Deal::query()->withOutcome(StageOutcome::Open)->pluck('id')->all())->toBe([$open->id]);
});

test('a pipeline whose closing stage has a bespoke name is still counted as closed', function () {
    $pipeline = savePipeline(pipelineData('Bespoke', [
        ['name' => 'Working', 'outcome' => 'open', 'probability' => 30, 'color' => 'blue'],
        ['name' => 'Signed', 'outcome' => 'won', 'probability' => 100, 'color' => 'emerald'],
    ], isDefault: true));

    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));
    app(MoveDealStageAction::class)($deal, 'signed');

    // "signed" is nowhere in DealStage, so the scope has to read the
    // configured outcomes rather than the enum.
    expect(Deal::query()->closed()->pluck('id')->all())->toBe([$deal->id])
        ->and(Deal::query()->open()->count())->toBe(0);
});

// -- Dates ---------------------------------------------------------------------

test('a deal past its expected close date is overdue only while it is open', function () {
    $pipeline = dealPipeline();
    $deal = createDeal(dealData([
        'pipeline_id' => $pipeline->id,
        'expected_close_date' => now()->subWeek()->format('Y-m-d'),
    ]));

    expect($deal->isOverdue())->toBeTrue();

    app(MoveDealStageAction::class)($deal, 'closed_won');

    // A won deal is not late, it is finished.
    expect($deal->fresh()->isOverdue())->toBeFalse();
});

test('days to close is counted from creation, and is null while open', function () {
    $pipeline = dealPipeline();

    Carbon\Carbon::setTestNow('2026-05-01 09:00:00');
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    expect($deal->daysToClose())->toBeNull();

    Carbon\Carbon::setTestNow('2026-05-11 09:00:00');
    app(MoveDealStageAction::class)($deal, 'closed_won');
    Carbon\Carbon::setTestNow();

    expect($deal->fresh()->daysToClose())->toBe(10);
});

// -- Deleting ------------------------------------------------------------------

test('deleting a deal is recoverable and keeps its timeline', function () {
    $pipeline = dealPipeline();
    $deal = createDeal(dealData(['pipeline_id' => $pipeline->id]));

    Note::factory()->on($deal)->create(['body' => 'said before it went']);

    app(DeleteDealAction::class)($deal);

    expect(Deal::query()->whereKey($deal->id)->exists())->toBeFalse()
        ->and(Deal::withTrashed()->whereKey($deal->id)->exists())->toBeTrue()
        // A restored deal with its history stripped is worse than one that was
        // never deleted.
        ->and($deal->notes()->count())->toBe(1);
});

// -- Lead conversion still agrees with the module ------------------------------

test('a converted deal lands on the default pipeline at its first open stage', function () {
    $pipeline = dealPipeline();

    $lead = Lead::factory()->create([
        'status' => LeadStatus::Qualified->value,
        'estimated_value' => '4000',
    ]);

    $result = app(ConvertLeadAction::class)(
        $lead,
        new LeadConversionData(createDeal: true),
        $lead->primaryAssignee(),
    );

    expect($result->deal->pipeline_id)->toBe($pipeline->id)
        ->and($result->deal->stage)->toBe('scoping')
        ->and($result->deal->weightedValue())->toBe(800.0);
});
