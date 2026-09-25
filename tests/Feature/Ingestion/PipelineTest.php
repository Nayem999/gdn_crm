<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Ingestion\Actions\ProcessIntegrationEventAction;
use App\Domain\Ingestion\Enums\DedupeAction;
use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\Enums\PayloadFilterOperator;
use App\Domain\Ingestion\IngestionTargets;
use App\Domain\Ingestion\IngestionWriters;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\DataSourceFilter;
use App\Domain\Ingestion\Models\DataSourceMapping;
use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Domain\Ingestion\PayloadReader;
use App\Domain\Leads\LeadImportSource;
use App\Domain\Leads\Models\Lead;
use App\Jobs\ProcessIntegrationEvent;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * A source pointed at leads, owned by somebody, with the usual mappings.
 */
function ingestionLeadSource(array $attributes = []): DataSource
{
    $owner = User::factory()->create();

    $source = DataSource::factory()->into('leads')->create([
        'default_owner_id' => $owner->id,
        ...$attributes,
    ]);

    DataSourceMapping::factory()->forSource($source)->mapping('contact.first_name', 'first_name')->create();
    DataSourceMapping::factory()->forSource($source)->mapping('contact.last_name', 'last_name')->create();
    DataSourceMapping::factory()->forSource($source)->mapping('contact.email', 'email')->create();
    DataSourceMapping::factory()->forSource($source)->mapping('company', 'company_name')->create();

    return $source->fresh();
}

function ingestionDeliver(DataSource $source, array|string $payload): IntegrationEvent
{
    $event = IntegrationEvent::factory()->forSource($source)->create([
        'payload' => is_string($payload) ? $payload : json_encode($payload),
    ]);

    return app(ProcessIntegrationEventAction::class)($event);
}

/**
 * @return array<string, mixed>
 */
function ingestionTaskPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'event' => 'task.created',
        'id' => 'task-1',
        'company' => 'Acme Industries',
        'contact' => [
            'first_name' => 'Dara',
            'last_name' => 'Okafor',
            'email' => 'dara@acme.test',
        ],
    ], $overrides);
}

beforeEach(function () {
    Cache::flush();
});

// -- End to end ----------------------------------------------------------------

test('a delivery becomes a record', function () {
    $source = ingestionLeadSource();

    $event = ingestionDeliver($source, ingestionTaskPayload());

    expect($event->status())->toBe(IntegrationEventStatus::Processed)
        ->and($event->outcome)->toBe('created');

    $lead = Lead::query()->firstOrFail();

    expect($lead->first_name)->toBe('Dara')
        ->and($lead->last_name)->toBe('Okafor')
        ->and($lead->email)->toBe('dara@acme.test')
        ->and($lead->company_name)->toBe('Acme Industries')
        // The event points at what it made, so the log answers "where did this
        // record come from".
        ->and($event->record_id)->toBe($lead->id)
        ->and($event->record_type)->toBe($lead->getMorphClass());
});

test('what was mapped is written down, so a failure can be read afterwards', function () {
    $source = ingestionLeadSource();

    $event = ingestionDeliver($source, ingestionTaskPayload());

    // toEqual, not toBe: a JSON column comes back with its keys in the
    // engine's own order — MySQL 8 sorts them, MariaDB keeps them — and the
    // mapping is read by key, never by position.
    expect($event->mapped_output)->toEqual([
        'first_name' => 'Dara',
        'last_name' => 'Okafor',
        'email' => 'dara@acme.test',
        'company_name' => 'Acme Industries',
    ]);
});

// -- Filter --------------------------------------------------------------------

test('a delivery the filter rejects is skipped, not failed', function () {
    $source = ingestionLeadSource();
    DataSourceFilter::factory()->forSource($source)
        ->rule('event', PayloadFilterOperator::Equals, 'task.created')->create();

    $event = ingestionDeliver($source->fresh(), ingestionTaskPayload(['event' => 'task.deleted']));

    // A source that keeps one event type discards most of what it is sent.
    // Calling that an error would bury the real ones.
    expect($event->status())->toBe(IntegrationEventStatus::Skipped)
        ->and($event->outcome)->toBe('filtered')
        ->and(Lead::query()->count())->toBe(0);
});

test('a delivery the filter accepts goes through', function () {
    $source = ingestionLeadSource();
    DataSourceFilter::factory()->forSource($source)
        ->rule('event', PayloadFilterOperator::Equals, 'task.created')->create();

    expect(ingestionDeliver($source->fresh(), ingestionTaskPayload())->status())->toBe(IntegrationEventStatus::Processed);
});

test('every filter has to pass', function () {
    $source = ingestionLeadSource();
    DataSourceFilter::factory()->forSource($source)->rule('event', PayloadFilterOperator::Equals, 'task.created')->create();
    DataSourceFilter::factory()->forSource($source)->rule('contact.email', PayloadFilterOperator::Exists)->create();

    $withoutEmail = ingestionTaskPayload();
    unset($withoutEmail['contact']['email']);

    expect(ingestionDeliver($source->fresh(), $withoutEmail)->status())->toBe(IntegrationEventStatus::Skipped);
});

test('each operator decides on its own', function (string $operator, ?string $value, array $payload, bool $passes) {
    expect(PayloadFilterOperator::from($operator)->matches($payload, 'event', $value))->toBe($passes);
})->with([
    'equals hit' => ['equals', 'task.created', ['event' => 'task.created'], true],
    'equals is case insensitive' => ['equals', 'Task.Created', ['event' => 'task.created'], true],
    'equals miss' => ['equals', 'task.created', ['event' => 'task.deleted'], false],
    'equals against nothing' => ['equals', 'task.created', [], false],
    'not equals hit' => ['not_equals', 'task.created', ['event' => 'task.deleted'], true],
    'not equals miss' => ['not_equals', 'task.created', ['event' => 'task.created'], false],
    // A missing value "is not" anything, which is what somebody filtering out
    // one event type expects.
    'not equals against nothing' => ['not_equals', 'task.created', [], true],
    'contains hit' => ['contains', 'creat', ['event' => 'task.created'], true],
    'contains miss' => ['contains', 'delete', ['event' => 'task.created'], false],
    'exists' => ['exists', null, ['event' => 'task.created'], true],
    'exists against null' => ['exists', null, ['event' => null], false],
    'exists against blank' => ['exists', null, ['event' => ''], false],
    'not exists' => ['not_exists', null, [], true],
]);

// -- Map -----------------------------------------------------------------------

test('a dotted path reaches into nested payloads', function () {
    expect(PayloadReader::value(ingestionTaskPayload(), 'contact.email'))->toBe('dara@acme.test')
        ->and(PayloadReader::value(ingestionTaskPayload(), 'company'))->toBe('Acme Industries')
        ->and(PayloadReader::value(ingestionTaskPayload(), 'contact.missing'))->toBeNull()
        ->and(PayloadReader::value(ingestionTaskPayload(), 'nothing.at.all'))->toBeNull()
        // A branch is not a value: returning it would put "Array" in a column.
        ->and(PayloadReader::value(ingestionTaskPayload(), 'contact'))->toBeNull();
});

test('a mapping naming a field the module does not declare is ignored', function () {
    $source = ingestionLeadSource();
    DataSourceMapping::factory()->forSource($source)->mapping('company', 'password')->create();

    $event = ingestionDeliver($source->fresh(), ingestionTaskPayload());

    // Checked against the module's declaration every time it is used, not only
    // when it was saved.
    expect($event->status())->toBe(IntegrationEventStatus::Processed)
        ->and(array_keys($event->mapped_output ?? []))->not->toContain('password');
});

test('a default fills in for an absent value', function () {
    $source = ingestionLeadSource();
    DataSourceMapping::factory()->forSource($source)
        ->mapping('contact.city', 'city')->withDefault('Unknown')->create();

    $event = ingestionDeliver($source->fresh(), ingestionTaskPayload());

    expect($event->mapped_output['city'] ?? null)->toBe('Unknown');
});

test('a required mapping with nothing to fill it fails the delivery', function () {
    $source = ingestionLeadSource();
    DataSourceMapping::factory()->forSource($source)
        ->mapping('contact.phone', 'phone')->required()->create();

    $event = ingestionDeliver($source->fresh(), ingestionTaskPayload());

    // A half record nobody can tell from a deliberate blank is worse than a
    // visible failure.
    expect($event->status())->toBe(IntegrationEventStatus::Failed)
        ->and($event->error)->toContain('phone')
        ->and(Lead::query()->count())->toBe(0);
});

test('a transform is applied to the value it maps', function () {
    $source = ingestionLeadSource();
    DataSourceMapping::factory()->forSource($source)
        ->mapping('contact.city', 'city')->transformedBy('upper')->create();

    $event = ingestionDeliver($source->fresh(), ingestionTaskPayload(['contact' => ['city' => 'exeter']]));

    expect($event->mapped_output['city'] ?? null)->toBe('EXETER');
});

test('an unknown transform passes the value through rather than failing', function () {
    $source = ingestionLeadSource();
    DataSourceMapping::factory()->forSource($source)
        ->mapping('contact.city', 'city')->transformedBy('invented_in_a_later_version')->create();

    $event = ingestionDeliver($source->fresh(), ingestionTaskPayload(['contact' => ['city' => 'Exeter']]));

    // A rule somebody removed should not stop every delivery a source sends.
    expect($event->status())->toBe(IntegrationEventStatus::Processed)
        ->and($event->mapped_output['city'] ?? null)->toBe('Exeter');
});

// -- Validate -------------------------------------------------------------------

test('a payload that would make a record the application refuses is refused here too', function () {
    $source = ingestionLeadSource();

    $event = ingestionDeliver($source, ingestionTaskPayload(['contact' => ['email' => 'not-an-email']]));

    // The module's own rules, the same ones an import obeys.
    expect($event->status())->toBe(IntegrationEventStatus::Failed)
        ->and($event->error)->toContain('email')
        ->and(Lead::query()->count())->toBe(0);
});

test('a body that is not a JSON object fails cleanly', function (string $body) {
    $event = ingestionDeliver(ingestionLeadSource(), $body);

    expect($event->status())->toBe(IntegrationEventStatus::Failed)
        ->and($event->error)->toContain('JSON')
        ->and(Lead::query()->count())->toBe(0);
})->with([
    'empty' => [''],
    'not json' => ['<xml/>'],
    'a bare scalar' => ['"hello"'],
    'a list' => ['[1,2,3]'],
]);

// -- Dedupe ---------------------------------------------------------------------

test('the same delivery twice creates one record', function () {
    $source = ingestionLeadSource(['external_id_path' => 'id']);

    $first = ingestionDeliver($source, ingestionTaskPayload());
    $second = ingestionDeliver($source->fresh(), ingestionTaskPayload(['contact' => ['last_name' => 'Okafor-Smith']]));

    expect(Lead::query()->count())->toBe(1)
        ->and($first->outcome)->toBe('created')
        ->and($second->outcome)->toBe('updated')
        ->and($second->record_id)->toBe($first->record_id)
        ->and(Lead::query()->firstOrFail()->last_name)->toBe('Okafor-Smith');
});

test('an update leaves alone the fields the delivery did not carry', function () {
    $source = ingestionLeadSource(['external_id_path' => 'id']);
    ingestionDeliver($source, ingestionTaskPayload());

    Lead::query()->firstOrFail()->forceFill(['city' => 'Exeter'])->save();

    ingestionDeliver($source->fresh(), ingestionTaskPayload(['contact' => ['last_name' => 'Okafor-Smith']]));

    // A DTO's fromArray() fills absent keys with null, so without merging the
    // record's current values this would blank every unmapped field.
    $lead = Lead::query()->firstOrFail();

    expect($lead->last_name)->toBe('Okafor-Smith')
        ->and($lead->city)->toBe('Exeter')
        ->and($lead->company_name)->toBe('Acme Industries');
});

test('two different external ids are two records', function () {
    $source = ingestionLeadSource(['external_id_path' => 'id']);

    ingestionDeliver($source, ingestionTaskPayload());
    ingestionDeliver($source->fresh(), ingestionTaskPayload(['id' => 'task-2', 'contact' => ['email' => 'other@acme.test']]));

    expect(Lead::query()->count())->toBe(2);
});

test('matching on a field works when there is no external id', function () {
    $source = ingestionLeadSource(['dedupe_fields' => ['email']]);

    ingestionDeliver($source, ingestionTaskPayload());
    ingestionDeliver($source->fresh(), ingestionTaskPayload(['contact' => ['first_name' => 'Dara-Jane']]));

    expect(Lead::query()->count())->toBe(1)
        ->and(Lead::query()->firstOrFail()->first_name)->toBe('Dara-Jane');
});

test('a blank match value matches nothing rather than everything', function () {
    $source = ingestionLeadSource(['dedupe_fields' => ['email']]);
    Lead::factory()->create(['email' => null]);

    $payload = ingestionTaskPayload();
    unset($payload['contact']['email']);

    $event = ingestionDeliver($source, $payload);

    // Matching on a blank would update whichever record happened to have a
    // blank in that column.
    expect($event->outcome)->toBe('created')
        ->and(Lead::query()->count())->toBe(2);
});

test('skip leaves the existing record untouched', function () {
    $source = ingestionLeadSource(['external_id_path' => 'id', 'dedupe_action' => DedupeAction::Skip->value]);

    ingestionDeliver($source, ingestionTaskPayload());
    $event = ingestionDeliver($source->fresh(), ingestionTaskPayload(['contact' => ['last_name' => 'Changed']]));

    expect($event->status())->toBe(IntegrationEventStatus::Skipped)
        ->and($event->outcome)->toBe('skipped')
        // It still says which record it matched, so the log is not a dead end.
        ->and($event->record_id)->not->toBeNull()
        ->and(Lead::query()->firstOrFail()->last_name)->toBe('Okafor');
});

test('create anyway makes a second record', function () {
    $source = ingestionLeadSource(['external_id_path' => 'id', 'dedupe_action' => DedupeAction::Create->value]);

    ingestionDeliver($source, ingestionTaskPayload());
    ingestionDeliver($source->fresh(), ingestionTaskPayload());

    // For a source whose deliveries are events rather than records — a form
    // submission is a new one every time, even from the same person.
    expect(Lead::query()->count())->toBe(2);
});

// -- Assign ----------------------------------------------------------------------

test('the configured owner owns what the source creates', function () {
    $owner = User::factory()->create();
    $source = ingestionLeadSource(['default_owner_id' => $owner->id]);

    ingestionDeliver($source, ingestionTaskPayload());

    expect(leadOwnerId(Lead::query()->firstOrFail()))->toBe($owner->id);
});

test('with no configured owner it falls back to whoever set the source up', function () {
    $creator = User::factory()->create();
    $source = ingestionLeadSource(['default_owner_id' => null, 'created_by_id' => $creator->id]);

    ingestionDeliver($source, ingestionTaskPayload());

    expect(leadOwnerId(Lead::query()->firstOrFail()))->toBe($creator->id);
});

test('a source with nobody to own its records fails rather than creating an orphan', function () {
    $source = ingestionLeadSource(['default_owner_id' => null, 'created_by_id' => null]);

    $event = ingestionDeliver($source, ingestionTaskPayload());

    // A record with no owner is how the visibility scope springs a leak.
    expect($event->status())->toBe(IntegrationEventStatus::Failed)
        ->and($event->error)->toContain('own')
        ->and(Lead::query()->count())->toBe(0);
});

// -- Sandbox ----------------------------------------------------------------------

test('a sandbox source maps everything and writes nothing', function () {
    $source = ingestionLeadSource(['is_sandbox' => true]);

    $event = ingestionDeliver($source, ingestionTaskPayload());

    expect($event->status())->toBe(IntegrationEventStatus::Skipped)
        ->and($event->outcome)->toBe('sandbox')
        // The operator can see exactly what would have been written.
        ->and($event->mapped_output['email'] ?? null)->toBe('dara@acme.test')
        ->and(Lead::query()->count())->toBe(0);
});

test('a sandbox source still reports a mapping failure', function () {
    $source = ingestionLeadSource(['is_sandbox' => true]);

    $event = ingestionDeliver($source, ingestionTaskPayload(['contact' => ['email' => 'not-an-email']]));

    // Otherwise sandbox mode would hide the very problems it exists to find.
    expect($event->status())->toBe(IntegrationEventStatus::Failed);
});

// -- Trigger -----------------------------------------------------------------------

test('an ingested record starts automations like a typed-in one', function () {
    $source = ingestionLeadSource();

    ingestionDeliver($source, ingestionTaskPayload());

    $lead = Lead::query()->firstOrFail();

    // Workflows fire from the model observer, so creating the record through
    // the module's own action is the whole of the "trigger" stage — there is
    // deliberately no second path that could behave differently.
    expect($lead->activities()->count())->toBeGreaterThan(0);
});

// -- Failure -----------------------------------------------------------------------

test('a failure rolls the record back but keeps the account of it', function () {
    $source = ingestionLeadSource();
    // Long enough to be refused by the column, so the write throws after the
    // action has begun.
    $event = ingestionDeliver($source, ingestionTaskPayload(['contact' => ['first_name' => str_repeat('x', 500)]]));

    expect($event->status())->toBe(IntegrationEventStatus::Failed)
        ->and($event->error)->not->toBeNull()
        // The row saying it failed has to survive the rollback that caused it.
        ->and(IntegrationEvent::query()->whereKey($event->id)->exists())->toBeTrue()
        ->and(Lead::query()->count())->toBe(0);
});

test('a failure never echoes the payload into the error', function () {
    $source = ingestionLeadSource();
    $secret = 'sensitive-value-'.uniqid();

    $event = ingestionDeliver($source, ingestionTaskPayload(['contact' => ['email' => $secret]]));

    expect($event->status())->toBe(IntegrationEventStatus::Failed)
        ->and($event->error)->not->toContain($secret);
});

test('an event already settled is not processed twice', function () {
    $source = ingestionLeadSource();
    $event = ingestionDeliver($source, ingestionTaskPayload());

    // The queue can deliver a job twice; doing the work again would create a
    // second record for one delivery.
    app(ProcessIntegrationEventAction::class)($event->fresh());

    expect(Lead::query()->count())->toBe(1);
});

test('the attempt is counted', function () {
    $event = ingestionDeliver(ingestionLeadSource(), ingestionTaskPayload());

    expect($event->attempts)->toBe(1);
});

// -- The job and the endpoint --------------------------------------------------------

test('accepting a delivery queues the processing rather than doing it', function () {
    Queue::fake();

    [$source, $credentials] = ingestableSource();
    $body = '{"id":"task-1"}';

    postIngest($source, $body, ingestHeaders($credentials, $body))->assertStatus(202);

    Queue::assertPushed(ProcessIntegrationEvent::class);
});

test('the job runs the pipeline for the event it names', function () {
    $source = ingestionLeadSource();
    $event = IntegrationEvent::factory()->forSource($source)->create([
        'payload' => json_encode(ingestionTaskPayload()),
    ]);

    (new ProcessIntegrationEvent($event->id))->handle(app(ProcessIntegrationEventAction::class));

    expect($event->fresh()->status())->toBe(IntegrationEventStatus::Processed)
        ->and(Lead::query()->count())->toBe(1);
});

test('the job carries an id, not a model', function () {
    // A serialised model is a snapshot of a row the job itself writes to.
    $parameters = (new ReflectionClass(ProcessIntegrationEvent::class))->getConstructor()?->getParameters() ?? [];

    expect($parameters)->toHaveCount(1)
        ->and($parameters[0]->getName())->toBe('eventId')
        ->and((string) $parameters[0]->getType())->toBe('int');
});

// -- The registry ------------------------------------------------------------------

test('every target module has something that can write into it', function () {
    // The two lists are the same list, and neither may grow without the other:
    // a source pointed at a module with no writer would accept deliveries it
    // could never act on.
    expect(IngestionWriters::keys())->toBe(IngestionTargets::keys());

    foreach (IngestionTargets::keys() as $module) {
        $writer = IngestionWriters::for($module);

        expect($writer)->not->toBeNull()
            ->and($writer?->fields())->not->toBeEmpty()
            ->and($writer?->modelClass())->toBe(IngestionTargets::modelClass($module));
    }
});

test('a writer declares the same fields importing does', function () {
    $writer = IngestionWriters::for('leads');

    // One declaration, read by both. A second list is always the one that
    // drifts.
    expect(array_keys($writer?->fields() ?? []))
        ->toBe(array_keys(app(LeadImportSource::class)->fields()));
});

test('the pipeline writes into other modules too', function () {
    $owner = User::factory()->create();
    $source = DataSource::factory()->into('accounts')->create(['default_owner_id' => $owner->id]);
    DataSourceMapping::factory()->forSource($source)->mapping('company', 'name')->create();

    $event = ingestionDeliver($source->fresh(), ingestionTaskPayload());

    expect($event->status())->toBe(IntegrationEventStatus::Processed)
        ->and(Account::query()->firstOrFail()->name)->toBe('Acme Industries')
        ->and(Contact::query()->count())->toBe(0);
});
