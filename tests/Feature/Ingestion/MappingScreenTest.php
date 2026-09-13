<?php

use App\Domain\CustomFields\Models\CustomField;
use App\Domain\Ingestion\Actions\DryRunMappingAction;
use App\Domain\Ingestion\IngestionWriters;
use App\Domain\Ingestion\MappingSuggester;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\DataSourceMapping;
use App\Domain\Ingestion\PayloadReader;
use App\Domain\Leads\Models\Lead;
use App\Livewire\Settings\SourceMapping;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

function ingestionSampleBody(): string
{
    return json_encode([
        'event' => 'task.created',
        'id' => 'task-9',
        'company' => 'Acme Industries',
        'contact' => ['first_name' => 'Dara', 'surname' => 'Okafor', 'email' => 'dara@acme.test'],
    ]);
}

beforeEach(function () {
    Cache::flush();
});

// -- Listen mode ----------------------------------------------------------------

test('listening is turned on with an expiry rather than a switch', function () {
    Carbon::setTestNow('2026-09-13 09:00:00');
    $source = DataSource::factory()->into('leads')->create();

    Livewire::actingAs(ingestionAdmin())
        ->test(SourceMapping::class, ['source' => $source])
        ->call('listen');

    // A mode somebody turns on and forgets quietly overwrites the sample
    // months later.
    expect($source->fresh()->isListening())->toBeTrue()
        ->and($source->fresh()->listening_until?->format('H:i'))
        ->toBe(Carbon::parse('2026-09-13 09:00:00')->addMinutes(DataSource::LISTEN_MINUTES)->format('H:i'));
});

test('a delivery while listening is kept as the sample, and listening stops', function () {
    [$source, $credentials] = ingestableSource();
    $source->forceFill(['listening_until' => now()->addMinutes(10)])->save();

    $body = ingestionSampleBody();
    postIngest($source->fresh(), $body, ingestHeaders($credentials, $body))->assertStatus(202);

    $source->refresh();

    expect($source->sample_payload)->toBe($body)
        ->and($source->sample_captured_at)->not->toBeNull()
        // One real example. Leaving it on would replace it with whatever
        // arrived next while somebody was still reading it.
        ->and($source->isListening())->toBeFalse();
});

test('a delivery when not listening leaves the sample alone', function () {
    [$source, $credentials] = ingestableSource();
    $source->forceFill(['sample_payload' => '{"kept":true}'])->save();

    $body = ingestionSampleBody();
    postIngest($source->fresh(), $body, ingestHeaders($credentials, $body))->assertStatus(202);

    expect($source->fresh()->sample_payload)->toBe('{"kept":true}');
});

test('a payload the processor would choke on is still captured', function () {
    [$source, $credentials] = ingestableSource();
    $source->forceFill(['listening_until' => now()->addMinutes(10)])->save();

    // The payloads worth capturing are exactly the ones that do not work yet.
    $body = '{"nothing":"the mapping knows about"}';
    postIngest($source->fresh(), $body, ingestHeaders($credentials, $body))->assertStatus(202);

    expect($source->fresh()->sample_payload)->toBe($body);
});

test('listening can be stopped by hand', function () {
    $source = DataSource::factory()->into('leads')->create(['listening_until' => now()->addMinutes(10)]);

    Livewire::actingAs(ingestionAdmin())
        ->test(SourceMapping::class, ['source' => $source])
        ->call('stopListening');

    expect($source->fresh()->isListening())->toBeFalse();
});

test('an expired listening window is not listening', function () {
    Carbon::setTestNow('2026-09-13 09:00:00');
    $source = DataSource::factory()->into('leads')->create(['listening_until' => now()->addMinutes(10)]);

    Carbon::setTestNow('2026-09-13 09:20:00');

    expect($source->fresh()->isListening())->toBeFalse();
});

// -- The sample ------------------------------------------------------------------

test('every path in the sample is offered', function () {
    $source = DataSource::factory()->into('leads')->create(['sample_payload' => ingestionSampleBody()]);

    expect($source->samplePaths())->toBe([
        'event',
        'id',
        'company',
        'contact.first_name',
        'contact.surname',
        'contact.email',
    ]);
});

test('paths are listed for nested objects but not for their branches', function () {
    // A branch is not a value — mapping one would put "Array" in a column.
    expect(PayloadReader::paths(['a' => ['b' => ['c' => 1]], 'd' => 2]))->toBe(['a.b.c', 'd']);
});

test('a sample that is not JSON offers nothing rather than breaking the screen', function () {
    $source = DataSource::factory()->into('leads')->create(['sample_payload' => 'not json']);

    expect($source->samplePaths())->toBe([])
        ->and($source->samplePayload())->toBeNull();
});

test('the screen shows the sample and its paths', function () {
    $source = DataSource::factory()->into('leads')->create([
        'sample_payload' => ingestionSampleBody(),
        'sample_captured_at' => now(),
    ]);

    Livewire::actingAs(ingestionAdmin())
        ->test(SourceMapping::class, ['source' => $source])
        ->assertOk()
        ->assertSee('contact.email')
        ->assertSee('Acme Industries');
});

// -- Suggestions -------------------------------------------------------------------

test('obvious pairs are suggested', function () {
    $fields = IngestionWriters::for('leads')?->fields() ?? [];
    $paths = PayloadReader::paths(json_decode(ingestionSampleBody(), true));

    $suggestions = MappingSuggester::suggest($paths, $fields);

    expect($suggestions['first_name'] ?? null)->toBe('contact.first_name')
        ->and($suggestions['email'] ?? null)->toBe('contact.email')
        // Matched on the last segment: the prefix is somebody else's envelope.
        ->and($suggestions['company_name'] ?? null)->toBe('company');
});

test('a field with nothing like it in the sample is not guessed at', function () {
    $fields = IngestionWriters::for('leads')?->fields() ?? [];

    $suggestions = MappingSuggester::suggest(['event', 'id'], $fields);

    // Noise in a screen like this is worse than a blank row: a wrong mapping
    // that looks deliberate gets saved.
    expect($suggestions)->toBe([]);
});

test('one path is never suggested for two fields', function () {
    $fields = IngestionWriters::for('leads')?->fields() ?? [];

    $suggestions = MappingSuggester::suggest(['email', 'contact.email'], $fields);

    expect(array_count_values(array_values($suggestions)))
        ->each(fn ($count) => $count->toBe(1));
});

test('the screen suggests rows without touching what is already mapped', function () {
    $source = DataSource::factory()->into('leads')->create(['sample_payload' => ingestionSampleBody()]);
    DataSourceMapping::factory()->forSource($source)->mapping('id', 'email')->create();

    $screen = Livewire::actingAs(ingestionAdmin())
        ->test(SourceMapping::class, ['source' => $source->fresh()])
        ->call('suggest');

    $rows = collect($screen->get('rows'));

    // Overwriting somebody's careful mapping with a guess is the one thing a
    // suggestion must not do.
    expect($rows->firstWhere('target_field', 'email')['source_path'])->toBe('id')
        ->and($rows->pluck('target_field'))->toContain('first_name');
});

// -- Saving ----------------------------------------------------------------------

test('the mapping saves and reads back', function () {
    $source = DataSource::factory()->into('leads')->create(['sample_payload' => ingestionSampleBody()]);

    Livewire::actingAs(ingestionAdmin())
        ->test(SourceMapping::class, ['source' => $source])
        ->call('addRow')
        ->set('rows.0.source_path', 'contact.email')
        ->set('rows.0.target_field', 'email')
        ->set('rows.0.is_required', true)
        ->call('save')
        ->assertHasNoErrors();

    $mapping = $source->fresh()->mappings->firstOrFail();

    expect($mapping->source_path)->toBe('contact.email')
        ->and($mapping->target_field)->toBe('email')
        ->and($mapping->is_required)->toBeTrue()
        ->and($mapping->position)->toBe(0);
});

test('a field the module does not offer is refused rather than stored', function () {
    $source = DataSource::factory()->into('leads')->create();

    Livewire::actingAs(ingestionAdmin())
        ->test(SourceMapping::class, ['source' => $source])
        ->call('addRow')
        ->set('rows.0.source_path', 'contact.email')
        ->set('rows.0.target_field', 'password')
        ->call('save')
        ->assertHasErrors(['rows.0.target_field']);

    // A mapping naming a field that does not exist is ignored at run time, so
    // storing one produces a rule that silently does nothing.
    expect($source->fresh()->mappings)->toHaveCount(0);
});

test('a row needs both halves', function () {
    $source = DataSource::factory()->into('leads')->create();

    Livewire::actingAs(ingestionAdmin())
        ->test(SourceMapping::class, ['source' => $source])
        ->call('addRow')
        ->call('save')
        ->assertHasErrors(['rows.0.source_path', 'rows.0.target_field']);
});

test('removing a row re-indexes the rest', function () {
    $source = DataSource::factory()->into('leads')->create();
    DataSourceMapping::factory()->forSource($source)->mapping('a', 'first_name')->create(['position' => 0]);
    DataSourceMapping::factory()->forSource($source)->mapping('b', 'last_name')->create(['position' => 1]);

    $screen = Livewire::actingAs(ingestionAdmin())
        ->test(SourceMapping::class, ['source' => $source->fresh()])
        ->call('removeRow', 0);

    // Re-indexed, or Livewire hands row 0's state to whatever slid into it.
    expect(array_keys($screen->get('rows')))->toBe([0])
        ->and($screen->get('rows')[0]['target_field'])->toBe('last_name');
});

test('saving replaces the mapping rather than adding to it', function () {
    $source = DataSource::factory()->into('leads')->create();
    DataSourceMapping::factory()->forSource($source)->mapping('a', 'first_name')->create();

    Livewire::actingAs(ingestionAdmin())
        ->test(SourceMapping::class, ['source' => $source->fresh()])
        ->call('removeRow', 0)
        ->call('addRow')
        ->set('rows.0.source_path', 'contact.email')
        ->set('rows.0.target_field', 'email')
        ->call('save')
        ->assertHasNoErrors();

    expect($source->fresh()->mappings->pluck('target_field')->all())->toBe(['email']);
});

test('a custom field is recognised as one', function () {
    $source = DataSource::factory()->into('leads')->create();

    CustomField::query()->create([
        'module' => 'leads',
        'key' => 'referred_by',
        'label' => 'Referred by',
        'type' => 'text',
        'is_active' => true,
        'position' => 0,
    ]);

    Livewire::actingAs(ingestionAdmin())
        ->test(SourceMapping::class, ['source' => $source])
        ->call('addRow')
        ->set('rows.0.source_path', 'referrer')
        ->set('rows.0.target_field', 'referred_by')
        ->call('save')
        ->assertHasNoErrors();

    expect($source->fresh()->mappings->firstOrFail()->is_custom_field)->toBeTrue();
});

// -- Dry run -----------------------------------------------------------------------

test('the dry run shows what would be made, and makes nothing', function () {
    $source = ingestionLeadSource(['sample_payload' => json_encode(ingestionTaskPayload())]);

    $result = app(DryRunMappingAction::class)($source->fresh());

    expect($result['errors'])->toBe([])
        ->and($result['mapped']?->all())->toBe([
            'first_name' => 'Dara',
            'last_name' => 'Okafor',
            'email' => 'dara@acme.test',
            'company_name' => 'Acme Industries',
        ])
        // The reason anybody trusts a dry run is that it is the real thing with
        // the last step removed.
        ->and(Lead::query()->count())->toBe(0);
});

test('the dry run reports what the pipeline would refuse', function () {
    $source = ingestionLeadSource([
        'sample_payload' => json_encode(ingestionTaskPayload(['contact' => ['email' => 'not-an-email']])),
    ]);

    $result = app(DryRunMappingAction::class)($source->fresh());

    // The module's own rules, so it refuses exactly what the pipeline would.
    expect($result['errors'])->not->toBeEmpty()
        ->and(implode(' ', $result['errors']))->toContain('email');
});

test('the dry run names a required field the payload has not got', function () {
    $source = ingestionLeadSource(['sample_payload' => json_encode(ingestionTaskPayload())]);
    DataSourceMapping::factory()->forSource($source)->mapping('contact.phone', 'phone')->required()->create();

    $result = app(DryRunMappingAction::class)($source->fresh());

    expect(implode(' ', $result['errors']))->toContain('phone');
});

test('the dry run says so when there is no sample yet', function () {
    $source = DataSource::factory()->into('leads')->create();

    $result = app(DryRunMappingAction::class)($source);

    expect($result['mapped'])->toBeNull()
        ->and(implode(' ', $result['errors']))->toContain('no sample payload');
});

test('the dry run can be given a payload instead of using the sample', function () {
    $source = ingestionLeadSource();

    $result = app(DryRunMappingAction::class)(
        $source->fresh(),
        json_encode(ingestionTaskPayload(['contact' => ['first_name' => 'Given']]))
    );

    expect($result['mapped']?->row['first_name'] ?? null)->toBe('Given');
});

test('the screen runs it', function () {
    $source = ingestionLeadSource(['sample_payload' => json_encode(ingestionTaskPayload())]);

    Livewire::actingAs(ingestionAdmin())
        ->test(SourceMapping::class, ['source' => $source->fresh()])
        ->call('dryRun')
        ->assertSet('dryRunErrors', [])
        ->assertSee('dara@acme.test');

    expect(Lead::query()->count())->toBe(0);
});

// -- Access --------------------------------------------------------------------------

test('the screen needs the manage permission', function () {
    $source = DataSource::factory()->into('leads')->create();

    Livewire::actingAs(ingestionUser(['integrations.view']))
        ->test(SourceMapping::class, ['source' => $source])
        ->assertForbidden();

    Livewire::actingAs(User::factory()->create())
        ->test(SourceMapping::class, ['source' => $source])
        ->assertForbidden();
});

test('the route resolves', function () {
    $source = DataSource::factory()->into('leads')->create();

    $this->actingAs(ingestionAdmin())
        ->get(route('settings.data-sources.mapping', $source->id))
        ->assertOk();
});

// -- The pipeline writes custom fields too ---------------------------------------------

test('a mapped custom field reaches the record', function () {
    CustomField::query()->create([
        'module' => 'leads',
        'key' => 'referred_by',
        'label' => 'Referred by',
        'type' => 'text',
        'is_active' => true,
        'position' => 0,
    ]);

    $source = ingestionLeadSource();
    DataSourceMapping::factory()->forSource($source)
        ->mapping('referrer', 'referred_by')->create(['is_custom_field' => true]);

    ingestionDeliver($source->fresh(), ingestionTaskPayload(['referrer' => 'A colleague']));

    expect(Lead::query()->firstOrFail()->customField('referred_by'))->toBe('A colleague');
});

test('a mapping naming a custom field that has been deleted is ignored', function () {
    $source = ingestionLeadSource();
    DataSourceMapping::factory()->forSource($source)
        ->mapping('referrer', 'gone_away')->create(['is_custom_field' => true]);

    $event = ingestionDeliver($source->fresh(), ingestionTaskPayload(['referrer' => 'A colleague']));

    // Checked against the definitions rather than trusted from the mapping.
    expect($event->status()->value)->toBe('processed')
        ->and(array_keys($event->mapped_output ?? []))->not->toContain('gone_away');
});
