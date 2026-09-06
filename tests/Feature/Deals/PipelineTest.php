<?php

use App\Domain\Deals\Actions\DeletePipelineAction;
use App\Domain\Deals\Actions\ReorderPipelinesAction;
use App\Domain\Deals\Actions\ReorderPipelineStagesAction;
use App\Domain\Deals\Actions\SavePipelineAction;
use App\Domain\Deals\Actions\SetDefaultPipelineAction;
use App\Domain\Deals\DTOs\PipelineData;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\Pipeline;
use App\Domain\Deals\Models\PipelineStage;
use Database\Seeders\PipelinesSeeder;

/**
 * @param  array<int, array<string, mixed>>  $stages
 */
function pipelineData(string $name, array $stages, bool $isDefault = false, ?string $description = null): PipelineData
{
    return PipelineData::fromArray([
        'name' => $name,
        'description' => $description,
        'is_default' => $isDefault,
        'stages' => $stages,
    ]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function threeStages(): array
{
    return [
        ['name' => 'Working', 'outcome' => 'open', 'probability' => 40, 'color' => 'blue'],
        ['name' => 'Closed won', 'outcome' => 'won', 'probability' => 100, 'color' => 'emerald'],
        ['name' => 'Closed lost', 'outcome' => 'lost', 'probability' => 0, 'color' => 'rose'],
    ];
}

function savePipeline(PipelineData $data, ?Pipeline $pipeline = null): Pipeline
{
    return app(SavePipelineAction::class)($data, $pipeline);
}

// -- Creating ------------------------------------------------------------------

test('a pipeline is created with its stages in the order they were given', function () {
    $pipeline = savePipeline(pipelineData('Renewals', threeStages(), description: 'For existing customers'));

    expect($pipeline->name)->toBe('Renewals')
        ->and($pipeline->description)->toBe('For existing customers')
        ->and($pipeline->stages->pluck('name')->all())->toBe(['Working', 'Closed won', 'Closed lost'])
        ->and($pipeline->stages->pluck('position')->all())->toBe([0, 1, 2]);
});

test('a stage key is derived from its name and never taken from the caller', function () {
    $pipeline = savePipeline(pipelineData('New logo', [
        // A key the caller made up. It names no stage on this pipeline, so it
        // is ignored and a key is derived instead.
        ['name' => 'First contact', 'outcome' => 'open', 'probability' => 10, 'color' => 'slate', 'key' => 'admin_backdoor'],
        ['name' => 'Closed won', 'outcome' => 'won', 'probability' => 100, 'color' => 'emerald'],
    ]));

    expect($pipeline->stages->pluck('key')->all())->toBe(['first_contact', 'closed_won']);
});

test('two stages with the same name still get distinct keys', function () {
    expect(PipelineStage::keyFrom('Review', ['review']))->toBe('review_2')
        ->and(PipelineStage::keyFrom('Review', ['review', 'review_2']))->toBe('review_3')
        // A name that slugs away to nothing still has to produce something.
        ->and(PipelineStage::keyFrom('!!!'))->toBe('stage');
});

test('a pipeline cannot be saved without a stage', function () {
    expect(fn () => savePipeline(pipelineData('Empty', [])))
        ->toThrow(RuntimeException::class, 'at least one stage');

    expect(Pipeline::query()->where('name', 'Empty')->exists())->toBeFalse();
});

test('two stages cannot share a name', function () {
    expect(fn () => savePipeline(pipelineData('Confusing', [
        ['name' => 'Review', 'outcome' => 'open', 'probability' => 10, 'color' => 'slate'],
        ['name' => 'review', 'outcome' => 'open', 'probability' => 20, 'color' => 'blue'],
    ])))->toThrow(RuntimeException::class, 'cannot both be called');
});

// -- Updating ------------------------------------------------------------------

test('renaming a stage keeps its key, so the deals in it stay put', function () {
    $pipeline = savePipeline(pipelineData('Sales', threeStages()));
    $stage = $pipeline->stages->firstWhere('key', 'working');

    Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage' => 'working']);

    savePipeline(pipelineData('Sales', [
        ['name' => 'In progress', 'outcome' => 'open', 'probability' => 45, 'color' => 'blue', 'key' => $stage->key],
        ['name' => 'Closed won', 'outcome' => 'won', 'probability' => 100, 'color' => 'emerald', 'key' => 'closed_won'],
        ['name' => 'Closed lost', 'outcome' => 'lost', 'probability' => 0, 'color' => 'rose', 'key' => 'closed_lost'],
    ]), $pipeline);

    $renamed = $pipeline->fresh()->stages->firstWhere('key', 'working');

    expect($renamed->name)->toBe('In progress')
        ->and($renamed->probability)->toBe(45)
        ->and(Deal::query()->where('stage', 'working')->count())->toBe(1);
});

test('a stage dropped from the form is removed when nothing is in it', function () {
    $pipeline = savePipeline(pipelineData('Sales', threeStages()));

    savePipeline(pipelineData('Sales', [
        ['name' => 'Closed won', 'outcome' => 'won', 'probability' => 100, 'color' => 'emerald', 'key' => 'closed_won'],
        ['name' => 'Closed lost', 'outcome' => 'lost', 'probability' => 0, 'color' => 'rose', 'key' => 'closed_lost'],
    ]), $pipeline);

    expect($pipeline->fresh()->stages->pluck('key')->all())->toBe(['closed_won', 'closed_lost']);
});

test('a stage with deals in it cannot be dropped', function () {
    $pipeline = savePipeline(pipelineData('Sales', threeStages()));
    Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage' => 'working']);

    expect(fn () => savePipeline(pipelineData('Sales', [
        ['name' => 'Closed won', 'outcome' => 'won', 'probability' => 100, 'color' => 'emerald', 'key' => 'closed_won'],
        ['name' => 'Closed lost', 'outcome' => 'lost', 'probability' => 0, 'color' => 'rose', 'key' => 'closed_lost'],
    ]), $pipeline))->toThrow(RuntimeException::class, 'still has 1 deal in it');

    // Refused whole: the rename that came with it must not have landed either.
    expect($pipeline->fresh()->stages->pluck('key')->all())->toContain('working');
});

// -- Probability ---------------------------------------------------------------

test('a closed stage takes the probability its outcome fixes, whatever was typed', function (string $outcome, int $typed, int $expected) {
    $pipeline = savePipeline(pipelineData('Sales '.$outcome, [
        ['name' => 'Ending', 'outcome' => $outcome, 'probability' => $typed, 'color' => 'slate'],
    ]));

    expect($pipeline->stages->first()->probability)->toBe($expected);
})->with([
    // A typed 60% against "Closed won" would skew every forecast quietly.
    ['won', 60, 100],
    ['lost', 60, 0],
    ['open', 60, 60],
]);

test('the outcome decides whether a stage closes the deal', function () {
    expect(StageOutcome::Open->isClosed())->toBeFalse()
        ->and(StageOutcome::Won->isClosed())->toBeTrue()
        ->and(StageOutcome::Lost->isClosed())->toBeTrue()
        ->and(StageOutcome::Open->fixedProbability())->toBeNull()
        ->and(StageOutcome::Won->fixedProbability())->toBe(100)
        ->and(StageOutcome::Lost->fixedProbability())->toBe(0);
});

// -- The default ---------------------------------------------------------------

test('making one pipeline the default clears it from every other', function () {
    $first = savePipeline(pipelineData('First', threeStages(), isDefault: true));
    $second = savePipeline(pipelineData('Second', threeStages(), isDefault: true));

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue()
        ->and(Pipeline::query()->where('is_default', true)->count())->toBe(1);
});

test('the first pipeline becomes the default even when it was not asked for', function () {
    $pipeline = savePipeline(pipelineData('Only one', threeStages(), isDefault: false));

    // Something has to be the default or a deal has nowhere to go.
    expect($pipeline->fresh()->is_default)->toBeTrue()
        ->and(Pipeline::default()->id)->toBe($pipeline->id);
});

test('a database whose default flag was lost still resolves one', function () {
    $first = savePipeline(pipelineData('First', threeStages()));
    savePipeline(pipelineData('Second', threeStages()));

    Pipeline::query()->update(['is_default' => false]);

    expect(Pipeline::default()->id)->toBe($first->id);

    app(SetDefaultPipelineAction::class)->ensureOneExists();

    expect($first->fresh()->is_default)->toBeTrue();
});

test('with no pipelines at all, resolving one gives null rather than failing', function () {
    expect(Pipeline::default())->toBeNull()
        ->and(app(SetDefaultPipelineAction::class)->ensureOneExists())->toBeNull();
});

// -- Deleting ------------------------------------------------------------------

test('the default pipeline cannot be removed', function () {
    $default = savePipeline(pipelineData('Default', threeStages(), isDefault: true));
    savePipeline(pipelineData('Other', threeStages()));

    expect($default->fresh()->deletionBlocker())->toContain('default pipeline cannot be removed')
        ->and(fn () => app(DeletePipelineAction::class)($default->fresh()))
        ->toThrow(RuntimeException::class, 'default pipeline cannot be removed');
});

test('the last pipeline cannot be removed', function () {
    $only = savePipeline(pipelineData('Only', threeStages()));
    // Take the default flag out of the picture so the "last one" rule is what
    // is being tested rather than the default rule.
    Pipeline::query()->update(['is_default' => false]);

    expect($only->fresh()->deletionBlocker())->toContain('only pipeline');
});

test('a pipeline with deals on it cannot be removed', function () {
    savePipeline(pipelineData('Default', threeStages(), isDefault: true));
    $busy = savePipeline(pipelineData('Busy', threeStages()));

    Deal::factory()->create(['pipeline_id' => $busy->id, 'stage' => 'working']);

    expect($busy->fresh()->deletionBlocker())->toContain('still has 1 deal')
        ->and(fn () => app(DeletePipelineAction::class)($busy->fresh()))
        ->toThrow(RuntimeException::class, 'still has 1 deal');
});

test('a removable pipeline goes, and takes its stages with it', function () {
    savePipeline(pipelineData('Default', threeStages(), isDefault: true));
    $spare = savePipeline(pipelineData('Spare', threeStages()));

    app(DeletePipelineAction::class)($spare->fresh());

    expect(Pipeline::query()->whereKey($spare->id)->exists())->toBeFalse()
        ->and(PipelineStage::query()->where('pipeline_id', $spare->id)->count())->toBe(0);
});

// -- Reordering ----------------------------------------------------------------

test('stages are reordered to the order given', function () {
    $pipeline = savePipeline(pipelineData('Sales', threeStages()));

    app(ReorderPipelineStagesAction::class)($pipeline, ['closed_lost', 'working', 'closed_won']);

    expect($pipeline->fresh()->stages->pluck('key')->all())
        ->toBe(['closed_lost', 'working', 'closed_won']);
});

test('a stage key from another pipeline cannot move anything', function () {
    $ours = savePipeline(pipelineData('Ours', threeStages()));

    // Different names, so its keys are genuinely different rather than the
    // same keys living on a second pipeline.
    $theirs = savePipeline(pipelineData('Theirs', [
        ['name' => 'Scoping', 'outcome' => 'open', 'probability' => 20, 'color' => 'blue'],
        ['name' => 'Signed', 'outcome' => 'won', 'probability' => 100, 'color' => 'emerald'],
        ['name' => 'Dropped', 'outcome' => 'lost', 'probability' => 0, 'color' => 'rose'],
    ]));

    $ourBefore = $ours->fresh()->stages->pluck('key')->all();
    $theirBefore = $theirs->fresh()->stages->pluck('key')->all();

    // Every key here is real, and every one of them belongs elsewhere.
    app(ReorderPipelineStagesAction::class)($ours, array_reverse($theirBefore));

    expect($ours->fresh()->stages->pluck('key')->all())->toBe($ourBefore)
        ->and($theirs->fresh()->stages->pluck('key')->all())->toBe($theirBefore);
});

test('two pipelines may use the same stage keys without colliding', function () {
    $ours = savePipeline(pipelineData('Ours', threeStages()));
    $theirs = savePipeline(pipelineData('Theirs', threeStages()));

    // Keys are unique within a pipeline, not across the installation — the
    // unique index is on (pipeline_id, key).
    expect($ours->stages->pluck('key')->all())->toBe($theirs->stages->pluck('key')->all())
        ->and($ours->id)->not->toBe($theirs->id);
});

test('a stage left out of the order keeps its place behind the ones listed', function () {
    $pipeline = savePipeline(pipelineData('Sales', threeStages()));

    app(ReorderPipelineStagesAction::class)($pipeline, ['closed_won']);

    expect($pipeline->fresh()->stages->pluck('key')->all())
        ->toBe(['closed_won', 'working', 'closed_lost']);
});

test('pipelines are reordered to the order given, and unknown ids are dropped', function () {
    $first = savePipeline(pipelineData('First', threeStages()));
    $second = savePipeline(pipelineData('Second', threeStages()));
    $third = savePipeline(pipelineData('Third', threeStages()));

    app(ReorderPipelinesAction::class)([$third->id, 999_999, $first->id, $second->id]);

    expect(Pipeline::query()->ordered()->pluck('name')->all())->toBe(['Third', 'First', 'Second']);
});

// -- What a deal makes of it ---------------------------------------------------

test('a deal reads its probability from the configured stage', function () {
    $pipeline = savePipeline(pipelineData('Sales', [
        ['name' => 'Working', 'outcome' => 'open', 'probability' => 40, 'color' => 'blue'],
        ['name' => 'Closed won', 'outcome' => 'won', 'probability' => 100, 'color' => 'emerald'],
    ]));

    $deal = Deal::factory()->create([
        'pipeline_id' => $pipeline->id,
        'stage' => 'working',
        'value' => 1000,
    ]);

    expect($deal->configuredStage()?->name)->toBe('Working')
        ->and($deal->weightedValue())->toBe(400.0)
        ->and($deal->isOpen())->toBeTrue();

    // Change the configuration and the forecast follows it.
    savePipeline(pipelineData('Sales', [
        ['name' => 'Working', 'outcome' => 'open', 'probability' => 80, 'color' => 'blue', 'key' => 'working'],
        ['name' => 'Closed won', 'outcome' => 'won', 'probability' => 100, 'color' => 'emerald', 'key' => 'closed_won'],
    ]), $pipeline);

    expect($deal->fresh()->weightedValue())->toBe(800.0);
});

test('a deal on no pipeline falls back to the default one', function () {
    (new PipelinesSeeder)->run();

    // What a deal created by 2.6's lead conversion looks like: a stage key and
    // no pipeline, from before pipelines existed.
    $deal = Deal::factory()->create([
        'pipeline_id' => null,
        'stage' => DealStage::Proposal->value,
        'value' => 2000,
    ]);

    expect($deal->configuredStage()?->name)->toBe('Proposal')
        ->and($deal->weightedValue())->toBe(1000.0);
});

test('a deal whose stage matches nothing configured still answers', function () {
    $pipeline = savePipeline(pipelineData('Sales', threeStages()));

    $deal = Deal::factory()->create([
        'pipeline_id' => $pipeline->id,
        'stage' => DealStage::Negotiation->value,
        'value' => 1000,
    ]);

    // No 'negotiation' stage on this pipeline, so the enum is the fallback
    // rather than a crash or a silent zero.
    expect($deal->configuredStage())->toBeNull()
        ->and($deal->weightedValue())->toBe(750.0)
        ->and($deal->isOpen())->toBeTrue();
});

// -- The seeded default --------------------------------------------------------

test('the seeded pipeline mirrors DealStage exactly, so 2.6 deals still resolve', function () {
    (new PipelinesSeeder)->run();

    $pipeline = Pipeline::default();

    expect($pipeline->name)->toBe(PipelinesSeeder::DEFAULT_NAME)
        ->and($pipeline->is_default)->toBeTrue()
        ->and($pipeline->stages->pluck('key')->all())
        ->toBe(array_map(fn (DealStage $stage) => $stage->value, DealStage::pipeline()));

    foreach (DealStage::pipeline() as $stage) {
        $configured = $pipeline->stageByKey($stage->value);

        expect($configured->name)->toBe($stage->label())
            ->and($configured->probability)->toBe($stage->probability())
            ->and($configured->color)->toBe($stage->color())
            ->and($configured->isClosed())->toBe($stage->isClosed());
    }
});

test('seeding twice changes nothing and keeps an administrator edit', function () {
    (new PipelinesSeeder)->run();

    $pipeline = Pipeline::default();
    $pipeline->stageByKey('proposal')->forceFill(['name' => 'Proposal sent'])->save();

    (new PipelinesSeeder)->run();

    expect(Pipeline::query()->count())->toBe(1)
        ->and(PipelineStage::query()->count())->toBe(count(DealStage::pipeline()))
        ->and(Pipeline::default()->stageByKey('proposal')->name)->toBe('Proposal sent');
});

// -- Stage relations -----------------------------------------------------------

test('a stage counts only the deals on its own pipeline', function () {
    $ours = savePipeline(pipelineData('Ours', threeStages()));
    $theirs = savePipeline(pipelineData('Theirs', threeStages()));

    Deal::factory()->count(2)->create(['pipeline_id' => $ours->id, 'stage' => 'working']);
    Deal::factory()->count(3)->create(['pipeline_id' => $theirs->id, 'stage' => 'working']);

    // Same stage key on both pipelines: matching on the key alone would count
    // the other pipeline's deals too.
    expect($ours->stages->firstWhere('key', 'working')->deals()->count())->toBe(2)
        ->and($theirs->stages->firstWhere('key', 'working')->deals()->count())->toBe(3);
});
