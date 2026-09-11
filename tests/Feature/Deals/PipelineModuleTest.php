<?php

use App\Domain\Deals\Actions\ReorderPipelinesAction;
use App\Domain\Deals\Actions\SavePipelineAction;
use App\Domain\Deals\Actions\SetDefaultPipelineAction;
use App\Domain\Deals\DTOs\PipelineData;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\Pipeline;
use App\Domain\Deals\PipelineModules;
use App\Domain\Deals\PipelineStatusCache;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\LeadFields;
use App\Domain\Leads\Models\Lead;
use App\Livewire\Deals\PipelineForm;
use App\Livewire\Deals\PipelinesIndex;
use App\Livewire\Leads\LeadsIndex;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * A pipeline for a module, saved through the action so the fixture is something
 * the application could have produced.
 *
 * @param  array<int, array{name: string, outcome?: StageOutcome, key?: string}>  $stages
 */
function modulePipeline(string $module, string $name, array $stages): Pipeline
{
    return app(SavePipelineAction::class)(PipelineData::fromArray([
        'module' => $module,
        'name' => $name,
        'is_default' => true,
        'stages' => array_map(fn (array $stage) => [
            'key' => $stage['key'] ?? null,
            'name' => $stage['name'],
            'color' => $stage['color'] ?? 'slate',
            'probability' => 0,
            'outcome' => ($stage['outcome'] ?? StageOutcome::Open)->value,
        ], $stages),
    ]));
}

/**
 * The lead statuses, named as the enum does, so a fixture can rename a subset
 * without inventing keys the module would refuse.
 *
 * @param  array<int, LeadStatus>  $only
 * @return array<int, array{name: string, key: string, outcome?: StageOutcome}>
 */
function leadStages(array $only, array $renames = []): array
{
    return array_map(fn (LeadStatus $status) => [
        'key' => $status->value,
        'name' => $renames[$status->value] ?? $status->label(),
        'outcome' => match ($status) {
            LeadStatus::Converted => StageOutcome::Won,
            LeadStatus::Unqualified => StageOutcome::Lost,
            default => StageOutcome::Open,
        },
    ], $only);
}

beforeEach(function () {
    Cache::flush();
    app(PipelineStatusCache::class)->flush();
});

// -- A pipeline belongs to a module -------------------------------------------

test('every module in the registry declares what it needs', function (string $module) {
    $spec = PipelineModules::all()[$module];

    expect($spec['label'])->not->toBeEmpty()
        ->and($spec['column'])->not->toBeEmpty()
        ->and(class_exists($spec['model']))->toBeTrue()
        ->and(enum_exists($spec['fallback']))->toBeTrue();
})->with(fn () => PipelineModules::keys());

test('a module the registry does not list is refused', function () {
    expect(PipelineModules::has('invoices'))->toBeFalse();

    expect(fn () => modulePipeline('invoices', 'Anything', [['name' => 'New']]))
        ->toThrow(RuntimeException::class, 'not a module');
});

test('an existing pipeline is a deals pipeline, and default() still means deals', function () {
    $deals = modulePipeline('deals', 'Standard sales', [['name' => 'New']]);
    modulePipeline('leads', 'Lead flow', leadStages([LeadStatus::New]));

    expect($deals->module)->toBe('deals')
        ->and(Pipeline::default()?->id)->toBe($deals->id);
});

test('each module has its own default', function () {
    $deals = modulePipeline('deals', 'Standard sales', [['name' => 'New']]);
    $leads = modulePipeline('leads', 'Lead flow', leadStages([LeadStatus::New]));

    expect(Pipeline::defaultFor('deals')?->id)->toBe($deals->id)
        ->and(Pipeline::defaultFor('leads')?->id)->toBe($leads->id)
        ->and(Pipeline::defaultFor('activities'))->toBeNull();
});

test('a pipeline cannot be moved between modules', function () {
    // Its records hold a stage key; moving the pipeline would strand every one
    // of them on a key that no longer names anything on their module.
    $pipeline = modulePipeline('deals', 'Standard sales', [['name' => 'New']]);

    app(SavePipelineAction::class)(PipelineData::fromArray([
        'module' => 'leads',
        'name' => 'Standard sales',
        'stages' => [['name' => 'New', 'outcome' => 'open', 'probability' => 0, 'color' => 'slate']],
    ]), $pipeline);

    expect($pipeline->fresh()->module)->toBe('deals');
});

// -- The bug this change would otherwise introduce ----------------------------

test('a leads pipeline never makes a deal count as closed', function () {
    // Before 4.4 every pipeline was a deals pipeline, so reading them all was
    // harmless. A leads stage keyed "lost" would otherwise close every deal in
    // a deals stage of that name.
    modulePipeline('deals', 'Standard sales', [
        ['name' => 'Working'],
        ['name' => 'Signed', 'outcome' => StageOutcome::Won],
    ]);

    modulePipeline('leads', 'Lead flow', leadStages([LeadStatus::New, LeadStatus::Unqualified]));

    $keys = Deal::closingStageKeys();

    expect($keys)->toContain('signed')
        // `unqualified` is a *lead* ending, and closingStageKeys is asked only
        // about deals.
        ->and($keys)->not->toContain('unqualified');
});

test('a deal in a stage named after a lead ending is still open', function () {
    modulePipeline('deals', 'Standard sales', [['name' => 'Working']]);
    modulePipeline('leads', 'Lead flow', leadStages([LeadStatus::New, LeadStatus::Unqualified]));

    $deal = Deal::factory()->create(['stage' => 'working']);

    expect(Deal::query()->open()->pluck('id')->all())->toBe([$deal->id]);
});

// -- One default and one order, per module ------------------------------------

test('making one module default does not disturb another', function () {
    $deals = modulePipeline('deals', 'Standard sales', [['name' => 'New']]);
    $leads = modulePipeline('leads', 'Lead flow', leadStages([LeadStatus::New]));

    app(SetDefaultPipelineAction::class)($leads);

    expect($deals->fresh()->is_default)->toBeTrue()
        ->and($leads->fresh()->is_default)->toBeTrue();
});

test('a second pipeline on the same module takes the default from the first', function () {
    $first = modulePipeline('deals', 'Standard sales', [['name' => 'New']]);
    $second = modulePipeline('deals', 'Renewals', [['name' => 'Due']]);

    app(SetDefaultPipelineAction::class)($second);

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue();
});

test('reordering one module does not renumber another', function () {
    // position is shared across the table, so an unscoped reorder would
    // renumber everything.
    $deals = modulePipeline('deals', 'Standard sales', [['name' => 'New']]);
    $dealsPosition = $deals->position;

    $leadsOne = modulePipeline('leads', 'Flow one', leadStages([LeadStatus::New]));
    $leadsTwo = modulePipeline('leads', 'Flow two', leadStages([LeadStatus::Contacted]));

    app(ReorderPipelinesAction::class)([$leadsTwo->id, $leadsOne->id], 'leads');

    expect($deals->fresh()->position)->toBe($dealsPosition)
        ->and(Pipeline::query()->forModule('leads')->ordered()->pluck('id')->all())
        ->toBe([$leadsTwo->id, $leadsOne->id]);
});

test('reordering cannot pull a pipeline out of another module', function () {
    $deals = modulePipeline('deals', 'Standard sales', [['name' => 'New']]);
    $position = $deals->position;
    $leads = modulePipeline('leads', 'Lead flow', leadStages([LeadStatus::New]));

    app(ReorderPipelinesAction::class)([$deals->id, $leads->id], 'leads');

    expect($deals->fresh()->position)->toBe($position);
});

// -- Statuses flow through the board and the filters --------------------------

test('a module with nothing configured falls back to its own enum', function () {
    expect(PipelineModules::isConfigured('leads'))->toBeFalse();

    $statuses = collect(PipelineModules::statuses('leads'));

    expect($statuses->pluck('value')->all())->toBe(
        collect(LeadStatus::pipeline())->map(fn (LeadStatus $s) => $s->value)->all()
    );
});

test('a configured pipeline renames, recolours, reorders and trims the board', function () {
    modulePipeline('leads', 'Lead flow', leadStages(
        [LeadStatus::Contacted, LeadStatus::New, LeadStatus::Converted],
        [LeadStatus::Contacted->value => 'Reached out', LeadStatus::New->value => 'Fresh'],
    ));

    app(PipelineStatusCache::class)->flush();

    $columns = collect(Livewire::actingAs(leadUser())->test(LeadsIndex::class)
        ->instance()
        ->dataViewKanbanColumns());

    // Renamed, reordered, and the statuses left out are gone from the board.
    expect($columns->pluck('label')->all())->toBe(['Reached out', 'Fresh', 'Converted'])
        ->and($columns->pluck('value')->all())->toBe(['contacted', 'new', 'converted']);
});

test('the same configured set reaches the filter builder', function () {
    modulePipeline('leads', 'Lead flow', leadStages(
        [LeadStatus::New, LeadStatus::Qualified],
        [LeadStatus::Qualified->value => 'Sales ready'],
    ));

    app(PipelineStatusCache::class)->flush();

    $options = LeadFields::filters()['status']->options;

    expect($options)->toBe(['new' => 'New', 'qualified' => 'Sales ready']);
});

test('the board and the filters never disagree about a module statuses', function () {
    modulePipeline('leads', 'Lead flow', leadStages([LeadStatus::New, LeadStatus::Nurturing]));
    app(PipelineStatusCache::class)->flush();

    $board = collect(Livewire::actingAs(leadUser())->test(LeadsIndex::class)
        ->instance()->dataViewKanbanColumns())->pluck('value')->all();

    expect($board)->toBe(array_keys(LeadFields::filters()['status']->options));
});

test('a filter on a configured status returns the right leads', function () {
    modulePipeline('leads', 'Lead flow', leadStages(
        [LeadStatus::New, LeadStatus::Nurturing],
        [LeadStatus::Nurturing->value => 'Warming up'],
    ));
    app(PipelineStatusCache::class)->flush();

    $user = leadUser();
    $warming = Lead::factory()->ownedBy($user)->create(['status' => LeadStatus::Nurturing->value, 'last_name' => 'Kowalczyk']);
    Lead::factory()->ownedBy($user)->create(['status' => LeadStatus::New->value, 'last_name' => 'Delacroix']);

    Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->set('filters', [
            'match' => 'all',
            'conditions' => [['field' => 'status', 'operator' => 'equals', 'value' => 'nurturing']],
            'groups' => [],
        ])
        ->assertSee('Kowalczyk')
        ->assertDontSee('Delacroix');

    expect($warming->status()->value)->toBe('nurturing');
});

// -- What an enum-backed module may not do ------------------------------------

test('an enum backed module refuses a stage key it does not recognise', function () {
    // Lead::status() resolves through LeadStatus::tryFrom and falls back to
    // New, so a stage keyed "working" would make every lead in it read as
    // "New" everywhere except the board that put them there.
    expect(fn () => modulePipeline('leads', 'Lead flow', [['name' => 'Working the phones']]))
        ->toThrow(RuntimeException::class, 'not a status');
});

test('deals may invent any stage they like', function () {
    // Their stage column is free-form; DealStage is a starting point, not a
    // constraint.
    $pipeline = modulePipeline('deals', 'Renewals', [
        ['name' => 'Renewal due'],
        ['name' => 'Signed again', 'outcome' => StageOutcome::Won],
    ]);

    expect($pipeline->stages()->ordered()->pluck('key')->all())
        ->toBe(['renewal_due', 'signed_again']);
});

test('an enum backed module is happy with its own keys, renamed', function () {
    $pipeline = modulePipeline('leads', 'Lead flow', leadStages(
        [LeadStatus::New],
        [LeadStatus::New->value => 'Just arrived'],
    ));

    expect($pipeline->stages()->first()->key)->toBe('new')
        ->and($pipeline->stages()->first()->name)->toBe('Just arrived');
});

// -- One read per request ------------------------------------------------------

test('a status set is read once per request and flushed when it changes', function () {
    $cache = app(PipelineStatusCache::class);

    expect(PipelineModules::isConfigured('leads'))->toBeFalse();

    modulePipeline('leads', 'Lead flow', leadStages([LeadStatus::New]));

    // The save flushes, so the very next read sees it.
    expect(PipelineModules::isConfigured('leads'))->toBeTrue();

    $cache->flush();

    expect(collect(PipelineModules::statuses('leads'))->pluck('value')->all())->toBe(['new']);
});

test('the leads list does not run a status query per rendered row', function () {
    modulePipeline('leads', 'Lead flow', leadStages([LeadStatus::New, LeadStatus::Contacted]));
    app(PipelineStatusCache::class)->flush();

    $user = leadUser();
    Lead::factory()->count(10)->ownedBy($user)->create(['status' => LeadStatus::New->value]);

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    Livewire::actingAs($user)->test(LeadsIndex::class);

    // A page of ten leads, not ten status lookups on top of it.
    expect($queries)->toBeLessThan(30);
});

// -- The admin screen ----------------------------------------------------------

test('the pipelines screen lists one module at a time', function () {
    modulePipeline('deals', 'Standard sales', [['name' => 'New']]);
    modulePipeline('leads', 'Lead flow', leadStages([LeadStatus::New]));

    Livewire::actingAs(pipelineUser())
        ->test(PipelinesIndex::class)
        ->assertSee('Standard sales')
        ->assertDontSee('Lead flow')
        ->call('selectModule', 'leads')
        ->assertSee('Lead flow')
        ->assertDontSee('Standard sales');
});

test('a module the screen does not offer is ignored', function () {
    Livewire::actingAs(pipelineUser())
        ->test(PipelinesIndex::class)
        ->call('selectModule', 'invoices')
        ->assertSet('module', 'deals');
});

test('a pipeline created from the screen belongs to the module on screen', function () {
    Livewire::actingAs(pipelineUser())
        ->test(PipelineForm::class, ['module' => 'leads'])
        ->set('name', 'Lead flow')
        ->set('stages', [[
            'key' => 'new',
            'name' => 'Fresh',
            'color' => 'slate',
            'probability' => '0',
            'outcome' => 'open',
        ]])
        ->call('save')
        ->assertHasNoErrors();

    expect(Pipeline::query()->where('name', 'Lead flow')->value('module'))->toBe('leads');
});

test('the form falls back to deals when the url names a module that does not exist', function () {
    Livewire::actingAs(pipelineUser())
        ->test(PipelineForm::class, ['module' => 'invoices'])
        ->assertSet('module', 'deals');
});

test('the screen says when a module is still on its built-in statuses', function () {
    expect(Livewire::actingAs(pipelineUser())
        ->test(PipelinesIndex::class)
        ->call('selectModule', 'leads')
        ->instance()
        ->usesFallback())->toBeTrue();

    modulePipeline('leads', 'Lead flow', leadStages([LeadStatus::New]));
    app(PipelineStatusCache::class)->flush();

    expect(Livewire::actingAs(pipelineUser())
        ->test(PipelinesIndex::class)
        ->call('selectModule', 'leads')
        ->instance()
        ->usesFallback())->toBeFalse();
});
