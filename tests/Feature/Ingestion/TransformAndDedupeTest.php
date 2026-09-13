<?php

use App\Domain\Ingestion\Enums\DedupeAction;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\DataSourceMapping;
use App\Domain\Ingestion\ValueTransformer;
use App\Domain\Leads\Models\Lead;
use App\Livewire\Settings\SourceMapping;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

function ingestionTransform(string $transform, string|int|float|bool|null $value, ?array $options = null): string|int|float|bool|null
{
    return app(ValueTransformer::class)->apply($transform, $value, $options);
}

beforeEach(function () {
    Cache::flush();
});

// -- Each transform type ---------------------------------------------------------

test('text tidying does what it says', function (string $transform, string $in, string $out) {
    expect(ingestionTransform($transform, $in))->toBe($out);
})->with([
    'trim' => ['trim', '  Dara  ', 'Dara'],
    'upper' => ['upper', 'exeter', 'EXETER'],
    'lower' => ['lower', 'EXETER', 'exeter'],
]);

test('a value map translates their vocabulary into ours', function () {
    $options = ['map' => ['new' => 'open', 'in progress' => 'working', 'done' => 'won']];

    expect(ingestionTransform('value_map', 'new', $options))->toBe('open')
        ->and(ingestionTransform('value_map', 'in progress', $options))->toBe('working');
});

test('a value map ignores capitalisation', function () {
    // A system that sends "Open" today sends "OPEN" the day somebody refactors
    // it.
    $options = ['map' => ['New' => 'open']];

    expect(ingestionTransform('value_map', 'NEW', $options))->toBe('open')
        ->and(ingestionTransform('value_map', 'new', $options))->toBe('open');
});

test('an unmapped value falls through to the fallback', function () {
    $options = ['map' => ['new' => 'open'], 'fallback' => 'unknown'];

    expect(ingestionTransform('value_map', 'something else', $options))->toBe('unknown');
});

test('an unmapped value with no fallback is left alone rather than blanked', function () {
    // A status we have not seen before is information. The validation rules are
    // where it gets refused if it is not allowed.
    expect(ingestionTransform('value_map', 'surprising', ['map' => ['new' => 'open']]))->toBe('surprising');
});

test('a value map with no map configured changes nothing', function () {
    expect(ingestionTransform('value_map', 'new', null))->toBe('new')
        ->and(ingestionTransform('value_map', 'new', ['fallback' => 'x']))->toBe('new');
});

test('a date is read in the format it was stated to be in', function (string $from, string $in, string $out) {
    expect(ingestionTransform('date', $in, ['from' => $from]))->toBe($out);
})->with([
    // The same string, two answers, decided by what the format says it is.
    'day first' => ['d/m/Y', '03/04/2026', '2026-04-03'],
    'month first' => ['m/d/Y', '03/04/2026', '2026-03-04'],
    'dotted' => ['d.m.Y', '25.12.2026', '2026-12-25'],
    'with a time' => ['d/m/Y H:i', '03/04/2026 14:30', '2026-04-03'],
]);

test('a date with no stated format falls back to what most APIs send', function (string $in, string $out) {
    expect(ingestionTransform('date', $in))->toBe($out);
})->with([
    'iso' => ['2026-04-03', '2026-04-03'],
    'iso with time' => ['2026-04-03T14:30:00Z', '2026-04-03'],
]);

test('a date it cannot read becomes nothing, never today', function (mixed $in, ?array $options) {
    // A confident wrong answer in a date column is worse than an empty one, and
    // far harder to notice.
    expect(ingestionTransform('date', $in, $options))->toBeNull();
})->with([
    'not a date at all' => ['not a date', null],
    'empty' => ['', null],
    'wrong format' => ['hello', ['from' => 'd/m/Y']],
]);

test('the output format can be asked for', function () {
    expect(ingestionTransform('date', '2026-04-03', ['to' => 'd/m/Y']))->toBe('03/04/2026');
});

test('a full name splits into the parts we keep', function (string $full, ?string $first, ?string $last) {
    expect(ingestionTransform('name_first', $full))->toBe($first)
        ->and(ingestionTransform('name_last', $full))->toBe($last);
})->with([
    'two names' => ['Dara Okafor', 'Dara', 'Okafor'],
    // The last part is the surname and everything before it is the rest, which
    // is right here where taking the second word would not be.
    'a longer name' => ['Maria del Carmen Okafor', 'Maria del Carmen', 'Okafor'],
    'untidy spacing' => ['  Dara   Okafor  ', 'Dara', 'Okafor'],
    // Somebody called Cher is called Cher — a single word is a first name with
    // no surname, not the other way round.
    'one word' => ['Cher', 'Cher', null],
    'nothing' => ['   ', null, null],
]);

test('digits are kept and everything else dropped', function () {
    expect(ingestionTransform('digits', '+44 (0) 1392 555 010'))->toBe('4401392555010')
        ->and(ingestionTransform('digits', 'no numbers here'))->toBeNull();
});

test('a null value is never transformed into something', function (string $transform) {
    expect(ingestionTransform($transform, null))->toBeNull();
})->with(['trim', 'upper', 'value_map', 'date', 'name_first', 'digits']);

test('an unknown transform passes the value through', function () {
    // A rule somebody removed should not stop every delivery a source sends.
    expect(ingestionTransform('invented_later', 'Dara'))->toBe('Dara');
});

// -- Transforms inside the pipeline ------------------------------------------------

test('a transform runs where the pipeline maps', function () {
    $source = ingestionLeadSource();
    DataSourceMapping::factory()->forSource($source)
        ->mapping('full_name', 'last_name')
        ->transformedBy('name_last')
        ->create();

    $event = ingestionDeliver($source->fresh(), ingestionTaskPayload(['full_name' => 'Dara Okafor']));

    expect($event->mapped_output['last_name'] ?? null)->toBe('Okafor');
});

test('a value map reaches the record', function () {
    $source = ingestionLeadSource();
    DataSourceMapping::factory()->forSource($source)
        ->mapping('their_source', 'source')
        // Into one of the module's own values, not an invented one: the
        // pipeline validates against the same rules an import obeys, so a map
        // pointing at something the enum has never heard of fails the delivery
        // rather than writing it.
        ->transformedBy('value_map', ['map' => ['web form' => 'web_form']])
        ->create();

    ingestionDeliver($source->fresh(), ingestionTaskPayload(['their_source' => 'Web Form']));

    expect(Lead::query()->firstOrFail()->source()->value)->toBe('web_form');
});

test('a value map pointing at something the module does not accept fails the delivery', function () {
    $source = ingestionLeadSource();
    DataSourceMapping::factory()->forSource($source)
        ->mapping('their_source', 'source')
        ->transformedBy('value_map', ['map' => ['web form' => 'website']])
        ->create();

    $event = ingestionDeliver($source->fresh(), ingestionTaskPayload(['their_source' => 'Web Form']));

    // A translation table is configuration, and a wrong one should be visible
    // rather than quietly writing a value nothing else in the application
    // understands.
    expect($event->status()->value)->toBe('failed')
        ->and($event->error)->toContain('source')
        ->and(Lead::query()->count())->toBe(0);
});

test('a transform that yields nothing lets the default take over', function () {
    $source = ingestionLeadSource();
    DataSourceMapping::factory()->forSource($source)
        ->mapping('their_phone', 'phone')
        ->transformedBy('digits')
        ->withDefault('0000')
        ->create();

    $event = ingestionDeliver($source->fresh(), ingestionTaskPayload(['their_phone' => 'call the office']));

    // The order matters: transform first, then the default fills the gap it
    // left. A default applied first would never be reached.
    expect($event->mapped_output['phone'] ?? null)->toBe('0000');
});

// -- Each dedupe action ------------------------------------------------------------

test('each action produces its own outcome', function (string $action, string $outcome, int $leads, string $lastName) {
    $source = ingestionLeadSource([
        'external_id_path' => 'id',
        'dedupe_action' => $action,
    ]);

    ingestionDeliver($source, ingestionTaskPayload());
    $second = ingestionDeliver($source->fresh(), ingestionTaskPayload(['contact' => ['last_name' => 'Changed']]));

    expect($second->outcome)->toBe($outcome)
        ->and(Lead::query()->count())->toBe($leads)
        ->and(Lead::query()->orderBy('id')->firstOrFail()->last_name)->toBe($lastName);
})->with([
    'update' => [DedupeAction::Update->value, 'updated', 1, 'Changed'],
    'skip' => [DedupeAction::Skip->value, 'skipped', 1, 'Okafor'],
    'create' => [DedupeAction::Create->value, 'created', 2, 'Okafor'],
]);

test('matching on several fields needs all of them', function () {
    $source = ingestionLeadSource(['dedupe_fields' => ['email', 'company_name']]);

    ingestionDeliver($source, ingestionTaskPayload());
    // Same email, different company: not the same record.
    $second = ingestionDeliver($source->fresh(), ingestionTaskPayload(['company' => 'Other Ltd']));

    expect($second->outcome)->toBe('created')
        ->and(Lead::query()->count())->toBe(2);
});

test('the sender id beats the field match when both are configured', function () {
    $source = ingestionLeadSource([
        'external_id_path' => 'id',
        'dedupe_fields' => ['email'],
    ]);

    ingestionDeliver($source, ingestionTaskPayload());
    // A different email but the same id — the same record of theirs.
    $second = ingestionDeliver($source->fresh(), ingestionTaskPayload(['contact' => ['email' => 'moved@acme.test']]));

    expect($second->outcome)->toBe('updated')
        ->and(Lead::query()->count())->toBe(1)
        ->and(Lead::query()->firstOrFail()->email)->toBe('moved@acme.test');
});

// -- The rule builder ----------------------------------------------------------------

test('a value map is written as lines and read back the same way', function () {
    $source = DataSource::factory()->into('leads')->create();

    Livewire::actingAs(ingestionAdmin())
        ->test(SourceMapping::class, ['source' => $source])
        ->call('addRow')
        ->set('rows.0.source_path', 'their_source')
        ->set('rows.0.target_field', 'source')
        ->set('rows.0.transform', 'value_map')
        ->set('rows.0.transform_map', "web form = website\nphone call = phone")
        ->set('rows.0.transform_fallback', 'other')
        ->call('save')
        ->assertHasNoErrors();

    $mapping = $source->fresh()->mappings->firstOrFail();

    expect($mapping->transform)->toBe('value_map')
        ->and($mapping->transform_options['map'])->toBe(['web form' => 'website', 'phone call' => 'phone'])
        ->and($mapping->transform_options['fallback'])->toBe('other');

    // And it comes back into the form as the same text.
    Livewire::actingAs(ingestionAdmin())
        ->test(SourceMapping::class, ['source' => $source->fresh()])
        ->assertSet('rows.0.transform_map', "web form = website\nphone call = phone");
});

test('switching a row to another transform drops the options it no longer uses', function () {
    $source = DataSource::factory()->into('leads')->create();
    DataSourceMapping::factory()->forSource($source)
        ->mapping('their_source', 'source')
        ->transformedBy('value_map', ['map' => ['a' => 'b']])
        ->create();

    Livewire::actingAs(ingestionAdmin())
        ->test(SourceMapping::class, ['source' => $source->fresh()])
        ->set('rows.0.transform', 'date')
        ->set('rows.0.transform_from', 'd/m/Y')
        ->call('save')
        ->assertHasNoErrors();

    $options = $source->fresh()->mappings->firstOrFail()->transform_options;

    // An old translation table left sitting in the column is a thing waiting to
    // confuse somebody.
    expect($options)->toBe(['from' => 'd/m/Y']);
});

test('a line with no equals sign is ignored rather than half stored', function () {
    $source = DataSource::factory()->into('leads')->create();

    Livewire::actingAs(ingestionAdmin())
        ->test(SourceMapping::class, ['source' => $source])
        ->call('addRow')
        ->set('rows.0.source_path', 'their_source')
        ->set('rows.0.target_field', 'source')
        ->set('rows.0.transform', 'value_map')
        ->set('rows.0.transform_map', "web form = website\njust a note\n\n  spaced = out  ")
        ->call('save');

    expect($source->fresh()->mappings->firstOrFail()->transform_options['map'])
        ->toBe(['web form' => 'website', 'spaced' => 'out']);
});

test('the matching rules save', function () {
    $source = DataSource::factory()->into('leads')->create();

    Livewire::actingAs(ingestionAdmin())
        ->test(SourceMapping::class, ['source' => $source])
        ->set('externalIdPath', 'id')
        ->set('dedupeFields', ['email'])
        ->set('dedupeAction', DedupeAction::Skip->value)
        ->call('saveMatching')
        ->assertHasNoErrors();

    $source->refresh();

    expect($source->external_id_path)->toBe('id')
        ->and($source->dedupe_fields)->toBe(['email'])
        ->and($source->dedupeAction())->toBe(DedupeAction::Skip);
});

test('a match field the module does not have is refused', function () {
    $source = DataSource::factory()->into('leads')->create();

    Livewire::actingAs(ingestionAdmin())
        ->test(SourceMapping::class, ['source' => $source])
        ->set('dedupeFields', ['password'])
        ->call('saveMatching')
        ->assertHasErrors(['dedupeFields.0']);

    expect($source->fresh()->dedupe_fields)->toBeNull();
});

test('an invented dedupe action is refused', function () {
    $source = DataSource::factory()->into('leads')->create();

    Livewire::actingAs(ingestionAdmin())
        ->test(SourceMapping::class, ['source' => $source])
        ->set('dedupeAction', 'delete_everything')
        ->call('saveMatching')
        ->assertHasErrors(['dedupeAction']);
});

test('clearing the match fields means always create', function () {
    $source = DataSource::factory()->into('leads')->create(['dedupe_fields' => ['email']]);

    Livewire::actingAs(ingestionAdmin())
        ->test(SourceMapping::class, ['source' => $source->fresh()])
        ->set('dedupeFields', [])
        ->call('saveMatching');

    expect($source->fresh()->dedupe_fields)->toBeNull();
});

test('the matching section needs the manage permission', function () {
    $source = DataSource::factory()->into('leads')->create();

    Livewire::actingAs(ingestionUser(['integrations.view']))
        ->test(SourceMapping::class, ['source' => $source])
        ->assertForbidden();
});

test('the screen offers every transform and every action', function () {
    $source = DataSource::factory()->into('leads')->create();

    $screen = Livewire::actingAs(ingestionAdmin())
        ->test(SourceMapping::class, ['source' => $source])
        ->instance();

    expect(array_keys($screen->transformOptions()))->toBe(array_keys(app(ValueTransformer::class)->options()))
        ->and(array_keys($screen->dedupeActionOptions()))->toBe(array_keys(DedupeAction::options()));
});
