<?php

use App\Domain\Ingestion\Actions\ApplyBlueprintAction;
use App\Domain\Ingestion\Actions\DryRunMappingAction;
use App\Domain\Ingestion\DTOs\SourceCredentials;
use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\IngestionBlueprints;
use App\Domain\Ingestion\IngestionWriters;
use App\Domain\Ingestion\IngestSignature;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Domain\Leads\Models\Lead;
use App\Domain\Workflows\Enums\WorkflowActionType;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowAction;
use App\Livewire\Settings\DataSources;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * The reference integration, set up and ready to receive: a source built from
 * the blueprint, with credentials and somebody to own what it makes.
 *
 * @return array{0: DataSource, 1: SourceCredentials, 2: User}
 */
function projectTaskSource(array $attributes = []): array
{
    $rep = User::factory()->create(['email_verified_at' => now()]);

    $source = DataSource::factory()->create([
        'name' => 'Delivery tool',
        'default_owner_id' => $rep->id,
        ...$attributes,
    ]);

    app(ApplyBlueprintAction::class)($source, 'project_task');
    $credentials = issueSecret($source->fresh());

    return [$source->fresh(), $credentials, $rep];
}

/**
 * What their project system posts when somebody raises work.
 *
 * @return array<string, mixed>
 */
function projectTaskPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'event' => 'task.created',
        'task' => [
            'id' => 'TASK-4192',
            'title' => 'Replace the roof lantern',
            'origin' => 'web',
            'project' => ['id' => 'PRJ-88', 'client' => 'Okafor Construction'],
            'requester' => [
                'name' => 'Dara Okafor',
                'email' => 'dara@okafor-construction.test',
                'phone' => '+44 (0) 1392 555 010',
            ],
        ],
    ], $overrides);
}

function postProjectTask(DataSource $source, array $payload, $credentials)
{
    $body = json_encode($payload);

    return postIngest($source, $body, ingestHeaders($credentials, $body));
}

beforeEach(function () {
    Cache::flush();
});

// -- End to end ------------------------------------------------------------------

test('a task posted by their system becomes a lead with the right values', function () {
    [$source, $credentials, $rep] = projectTaskSource();

    postProjectTask($source, projectTaskPayload(), $credentials)->assertStatus(202);

    $lead = Lead::query()->firstOrFail();

    expect($lead->first_name)->toBe('Dara')
        ->and($lead->last_name)->toBe('Okafor')
        ->and($lead->email)->toBe('dara@okafor-construction.test')
        // Their bracketed, spaced number, kept as digits.
        ->and($lead->phone)->toBe('4401392555010')
        ->and($lead->company_name)->toBe('Okafor Construction')
        ->and($lead->description)->toBe('Replace the roof lantern')
        // Their vocabulary translated into ours.
        ->and($lead->source()->value)->toBe('web_form')
        // Assigned, because a record with no owner is how visibility leaks.
        ->and($lead->owner_id)->toBe($rep->id);
});

test('the delivery is recorded against the lead it made', function () {
    [$source, $credentials] = projectTaskSource();

    postProjectTask($source, projectTaskPayload(), $credentials)->assertStatus(202);

    $event = IntegrationEvent::query()->firstOrFail();
    $lead = Lead::query()->firstOrFail();

    // "Where did this record come from" is answerable months later.
    expect($event->status())->toBe(IntegrationEventStatus::Processed)
        ->and($event->outcome)->toBe('created')
        ->and($event->record_id)->toBe($lead->id)
        ->and($event->external_id)->toBe('TASK-4192');
});

test('re-posting the same task updates rather than duplicating', function () {
    [$source, $credentials] = projectTaskSource();

    postProjectTask($source, projectTaskPayload(), $credentials)->assertStatus(202);

    // The same task, edited at their end: a new title and a corrected surname.
    postProjectTask($source, projectTaskPayload([
        'task' => [
            'title' => 'Replace the roof lantern and flashing',
            'requester' => ['name' => 'Dara Okafor-Smith'],
        ],
    ]), $credentials)->assertStatus(202);

    expect(Lead::query()->count())->toBe(1);

    $lead = Lead::query()->firstOrFail();

    expect($lead->last_name)->toBe('Okafor-Smith')
        ->and($lead->description)->toBe('Replace the roof lantern and flashing')
        // And the fields the second delivery did not change are still there.
        ->and($lead->company_name)->toBe('Okafor Construction')
        ->and($lead->phone)->toBe('4401392555010');
});

test('a different task from the same system is a different lead', function () {
    [$source, $credentials] = projectTaskSource();

    postProjectTask($source, projectTaskPayload(), $credentials)->assertStatus(202);
    postProjectTask($source, projectTaskPayload([
        'task' => [
            'id' => 'TASK-4193',
            'requester' => ['name' => 'Sam Adeyemi', 'email' => 'sam@other.test'],
        ],
    ]), $credentials)->assertStatus(202);

    expect(Lead::query()->count())->toBe(2);
});

test('the events they post that are not new work are ignored', function (string $event) {
    [$source, $credentials] = projectTaskSource();

    postProjectTask($source, projectTaskPayload(['event' => $event]), $credentials)->assertStatus(202);

    // A tool like this also posts comments, status changes and deletions, and
    // every one of those would otherwise become a lead.
    expect(Lead::query()->count())->toBe(0)
        ->and(IntegrationEvent::query()->firstOrFail()->status())->toBe(IntegrationEventStatus::Skipped);
})->with(['task.updated', 'task.deleted', 'comment.created']);

test('a task with no requester email fails rather than making half a lead', function () {
    [$source, $credentials] = projectTaskSource();

    $payload = projectTaskPayload();
    unset($payload['task']['requester']['email']);

    postProjectTask($source, $payload, $credentials)->assertStatus(202);

    expect(Lead::query()->count())->toBe(0)
        ->and(IntegrationEvent::query()->firstOrFail()->status())->toBe(IntegrationEventStatus::Failed);
});

// -- The rep is told -----------------------------------------------------------------

test('the assigned rep is notified, through the workflow engine', function () {
    [$source, $credentials, $rep] = projectTaskSource();

    // The "trigger" stage is not code in the pipeline — creating the record
    // through the module's own action fires the observers, and a workflow does
    // the rest. This is what proves that path is live.
    $workflow = Workflow::factory()->forModule('leads')->active()->create([
        'name' => 'Tell the rep about a new lead',
    ]);

    WorkflowAction::factory()->ofType(WorkflowActionType::SendNotification, [
        'recipient' => 'record_owner',
        'message' => 'A task was raised and is now a lead.',
    ])->create(['workflow_id' => $workflow->id]);

    postProjectTask($source, projectTaskPayload(), $credentials)->assertStatus(202);

    expect(Lead::query()->count())->toBe(1)
        ->and($rep->unreadNotifications()->count())->toBeGreaterThan(0);
});

// -- The blueprint itself --------------------------------------------------------------

test('the blueprint configures a source that is ready to receive', function () {
    [$source] = projectTaskSource();

    expect($source->target_module)->toBe('leads')
        ->and($source->external_id_path)->toBe('task.id')
        ->and($source->dedupe_fields)->toBe(['email'])
        ->and($source->filters)->toHaveCount(1)
        ->and($source->mappings->count())->toBeGreaterThan(4)
        // A sample, so the mapping screen has something to show before a single
        // delivery has arrived.
        ->and($source->hasSample())->toBeTrue()
        ->and($source->samplePaths())->toContain('task.requester.email');
});

test('the blueprint replaces what was there rather than adding to it', function () {
    [$source] = projectTaskSource();
    $first = $source->mappings->count();

    app(ApplyBlueprintAction::class)($source->fresh(), 'project_task');

    // A mixture of two configurations is worse than either.
    expect($source->fresh()->mappings)->toHaveCount($first)
        ->and($source->fresh()->filters)->toHaveCount(1);
});

test('a template the registry does not list is refused, never turned into a name', function () {
    $source = DataSource::factory()->into('leads')->create();

    expect(fn () => app(ApplyBlueprintAction::class)($source, 'rm_-rf'))
        ->toThrow(RuntimeException::class, 'no such template');
});

test('every mapping in a blueprint names a field its module actually has', function (string $key) {
    $blueprint = IngestionBlueprints::find($key);
    $writer = IngestionWriters::for($blueprint['target_module']);
    $declared = array_keys($writer?->fields() ?? []);

    // A blueprint is shipped configuration, so a field renamed in a module must
    // break the build rather than quietly stop being mapped.
    foreach ($blueprint['mappings'] as $mapping) {
        expect($declared)->toContain($mapping['field']);
    }

    foreach ($blueprint['dedupe_fields'] ?? [] as $field) {
        expect($declared)->toContain($field);
    }
})->with(fn () => IngestionBlueprints::keys());

test('a blueprint dry-runs cleanly against its own sample', function (string $key) {
    $source = DataSource::factory()->create(['default_owner_id' => User::factory()->create()->id]);
    app(ApplyBlueprintAction::class)($source, $key);

    $result = app(DryRunMappingAction::class)($source->fresh());

    // Shipped configuration that does not work against the example shipped with
    // it is worse than no example.
    expect($result['errors'])->toBe([])
        ->and($result['mapped']?->row)->not->toBeEmpty();
})->with(fn () => IngestionBlueprints::keys());

// -- Creating one from the screen ---------------------------------------------------------

test('a source created from a template arrives configured', function () {
    Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->call('add')
        ->set('blueprint', 'project_task')
        ->set('name', 'Delivery tool')
        ->call('save')
        ->assertHasNoErrors();

    $source = DataSource::query()->firstOrFail();

    expect($source->target_module)->toBe('leads')
        ->and($source->mappings)->not->toBeEmpty()
        ->and($source->external_id_path)->toBe('task.id');
});

test('choosing a template moves the module control to match it', function () {
    Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->call('add')
        ->set('blueprint', 'project_task')
        // Otherwise the two controls sit on screen disagreeing with each other.
        ->assertSet('target_module', 'leads');
});

test('a template is not offered when editing an existing source', function () {
    $source = DataSource::factory()->into('leads')->create();

    // Applying one over a source somebody has tuned would replace their rules
    // with the example's.
    Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->call('edit', $source->id)
        ->assertDontSeeHtml('wire:model.live="blueprint"');
});

test('editing a source built from a template does not re-apply it', function () {
    [$source] = projectTaskSource();

    Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->call('edit', $source->id)
        ->set('blueprint', 'project_task')
        ->set('name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect($source->fresh()->name)->toBe('Renamed')
        ->and($source->fresh()->mappings->count())->toBe($source->mappings->count());
});

test('an invented template is refused by the form', function () {
    Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->call('add')
        ->set('name', 'Something')
        ->set('target_module', 'leads')
        ->set('blueprint', 'not_a_template')
        ->call('save')
        ->assertHasErrors(['blueprint']);

    expect(DataSource::query()->count())->toBe(0);
});

// -- The address the other system is given ---------------------------------------------

test('the ingest address and the signature are what the integrator needs', function () {
    [$source, $credentials] = projectTaskSource();

    $body = json_encode(projectTaskPayload());

    // Exactly what their side has to build: the key in one header, and a
    // signature over the body with the timestamp inside it.
    postIngest($source, $body, [
        IngestSignature::KEY_HEADER => $credentials->key,
        IngestSignature::HEADER => IngestSignature::for($body, $credentials->signingSecret, time()),
    ])->assertStatus(202);

    expect(Lead::query()->count())->toBe(1)
        ->and($source->ingestUrl())->toContain($source->uuid);
});
