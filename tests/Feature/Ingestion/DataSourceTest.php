<?php

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\PermissionResolver;
use App\Domain\Ingestion\Actions\CreateDataSourceAction;
use App\Domain\Ingestion\Actions\DeleteDataSourceAction;
use App\Domain\Ingestion\Actions\UpdateDataSourceAction;
use App\Domain\Ingestion\DTOs\DataSourceData;
use App\Domain\Ingestion\Enums\DataSourceType;
use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\IngestionTargets;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Domain\Leads\Models\Lead;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity as AuditEntry;

/**
 * Named for the module, not the permission group: Pest helper functions are
 * global across the whole suite, and `integrationUser()` was already taken by
 * tests/Feature/CustomFields. A collision is a fatal redeclare that only the
 * full run shows — a file-scoped run passes happily.
 *
 * @param  array<int, string>  $permissions
 */
function ingestionUser(array $permissions = ['integrations.view']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

function ingestionAdmin(): User
{
    return ingestionUser(['integrations.view', 'integrations.manage']);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function dataSourceData(array $overrides = []): DataSourceData
{
    return DataSourceData::fromArray([
        'name' => 'Delivery tool',
        'description' => 'Tasks raised in the delivery tool become leads',
        'type' => 'push',
        'target_module' => 'leads',
        'is_active' => true,
        'is_sandbox' => false,
        ...$overrides,
    ]);
}

function createDataSource(?DataSourceData $data = null, ?User $actor = null): DataSource
{
    return app(CreateDataSourceAction::class)($data ?? dataSourceData(), $actor ?? ingestionAdmin());
}

// -- Creating ------------------------------------------------------------------

test('a source is created with what the form carried', function () {
    $actor = ingestionAdmin();

    $source = createDataSource(actor: $actor);

    expect($source->name)->toBe('Delivery tool')
        ->and($source->type())->toBe(DataSourceType::Push)
        ->and($source->target_module)->toBe('leads')
        ->and($source->is_active)->toBeTrue()
        ->and($source->is_sandbox)->toBeFalse()
        ->and($source->created_by_id)->toBe($actor->id);
});

test('every source is minted a uuid, and no two share one', function () {
    // A source without one has no ingest address at all, and the screen is not
    // the only thing that creates them.
    $first = createDataSource();
    $second = createDataSource(dataSourceData(['name' => 'Another']));

    expect($first->uuid)->not->toBeEmpty()
        ->and($second->uuid)->not->toBeEmpty()
        ->and($first->uuid)->not->toBe($second->uuid);
});

test('the ingest address is built from the uuid rather than stored', function () {
    $source = createDataSource();

    expect($source->ingestUrl())->toBe(url('/api/ingest/'.$source->uuid))
        // Not the primary key: a sequential id in a URL handed to an outside
        // system says how many integrations exist and invites a guess at the
        // next one.
        ->and($source->ingestUrl())->not->toContain('/'.$source->id);
});

test('the uuid cannot be written by a form or a payload', function () {
    $source = createDataSource();
    $original = $source->uuid;

    // An outside system has already been given this address.
    $source->fill(['uuid' => 'something-else'])->save();

    expect($source->fresh()->uuid)->toBe($original);
});

test('a target module outside the registry is refused, never turned into a class', function () {
    expect(fn () => createDataSource(dataSourceData(['target_module' => 'users'])))
        ->toThrow(RuntimeException::class, 'not a module data can be brought into');

    expect(fn () => createDataSource(dataSourceData(['target_module' => 'App\Models\User'])))
        ->toThrow(RuntimeException::class, 'not a module data can be brought into');
});

test('a source can be pointed at any module the registry lists', function (string $module) {
    $source = createDataSource(dataSourceData(['target_module' => $module]));

    expect($source->target_module)->toBe($module)
        ->and($source->targetModelClass())->toBe(IngestionTargets::modelClass($module))
        ->and($source->targetLabel())->toBe(IngestionTargets::label($module));
    // A closure, not a plain array: Pest resolves a dataset at collection time,
    // before the application is booted.
})->with(fn () => IngestionTargets::keys());

test('the registry resolves a key and nothing else', function () {
    expect(IngestionTargets::has('leads'))->toBeTrue()
        ->and(IngestionTargets::has('users'))->toBeFalse()
        ->and(IngestionTargets::modelClass('users'))->toBeNull()
        ->and(array_keys(IngestionTargets::options()))->toBe(IngestionTargets::keys());
});

// -- Updating ------------------------------------------------------------------

test('a source can be renamed, redescribed and switched between push and pull', function () {
    $source = createDataSource();

    app(UpdateDataSourceAction::class)($source, dataSourceData([
        'name' => 'Delivery tool (v2)',
        'description' => null,
        'type' => 'pull',
    ]));

    $source->refresh();

    expect($source->name)->toBe('Delivery tool (v2)')
        ->and($source->description)->toBeNull()
        ->and($source->type())->toBe(DataSourceType::Pull);
});

test('the target module can still be corrected while nothing has been written', function () {
    $source = createDataSource();
    // Test payloads arrive during setup; a mistake must still be fixable.
    IntegrationEvent::factory()->forSource($source)->count(2)->create();

    app(UpdateDataSourceAction::class)($source, dataSourceData(['target_module' => 'contacts']));

    expect($source->fresh()->target_module)->toBe('contacts');
});

test('the target module is fixed once the source has written a record', function () {
    $source = createDataSource();
    IntegrationEvent::factory()->forSource($source)->wrote(Lead::factory()->create())->create();

    // Re-aiming it would leave a history of leads hanging off something that
    // now claims to make contacts, and — once 8.5 lands — a set of mappings
    // naming columns the new module does not have.
    expect(fn () => app(UpdateDataSourceAction::class)($source, dataSourceData(['target_module' => 'contacts'])))
        ->toThrow(RuntimeException::class, 'already created records in Leads');

    expect($source->fresh()->target_module)->toBe('leads');
});

test('saving a source without changing its target is never blocked', function () {
    $source = createDataSource();
    IntegrationEvent::factory()->forSource($source)->wrote(Lead::factory()->create())->create();

    app(UpdateDataSourceAction::class)($source, dataSourceData(['name' => 'Renamed']));

    expect($source->fresh()->name)->toBe('Renamed');
});

test('an update cannot smuggle in an unknown target either', function () {
    $source = createDataSource();

    expect(fn () => app(UpdateDataSourceAction::class)($source, dataSourceData(['target_module' => 'users'])))
        ->toThrow(RuntimeException::class, 'not a module data can be brought into');
});

// -- The gate ------------------------------------------------------------------

test('a live source accepts deliveries and writes records', function () {
    $source = DataSource::factory()->create();

    expect($source->acceptsDeliveries())->toBeTrue()
        ->and($source->writesRecords())->toBeTrue()
        ->and(DataSource::forIngest($source->uuid)?->id)->toBe($source->id);
});

test('a disabled source refuses every delivery', function () {
    $source = DataSource::factory()->disabled()->create();

    // The first gate, and the cheapest: the answer before a signature is
    // checked or a body is read.
    expect($source->acceptsDeliveries())->toBeFalse()
        ->and($source->writesRecords())->toBeFalse()
        ->and(DataSource::forIngest($source->uuid))->toBeNull();
});

test('a removed source refuses every delivery', function () {
    $source = DataSource::factory()->create();

    app(DeleteDataSourceAction::class)($source);

    expect(DataSource::forIngest($source->uuid))->toBeNull();
});

test('an unknown address and a switched-off one are indistinguishable', function () {
    $off = DataSource::factory()->disabled()->create();

    // Both null, on purpose: saying which it was tells a caller which uuids
    // exist.
    expect(DataSource::forIngest($off->uuid))->toBeNull()
        ->and(DataSource::forIngest((string) Str::uuid()))->toBeNull();
});

test('a sandbox source still accepts deliveries, it just writes nothing', function () {
    $source = DataSource::factory()->sandbox()->create();

    // Sandbox is not "off": an integration being built needs to send real
    // payloads and see what they would produce.
    expect($source->acceptsDeliveries())->toBeTrue()
        ->and($source->writesRecords())->toBeFalse()
        ->and(DataSource::forIngest($source->uuid)?->id)->toBe($source->id);
});

test('a source that is both sandboxed and switched off accepts nothing', function () {
    $source = DataSource::factory()->sandbox()->disabled()->create();

    expect($source->acceptsDeliveries())->toBeFalse()
        ->and($source->writesRecords())->toBeFalse();
});

// -- Removing ------------------------------------------------------------------

test('removing a source keeps what it brought in', function () {
    $source = createDataSource();
    $event = IntegrationEvent::factory()->forSource($source)->create();

    app(DeleteDataSourceAction::class)($source);

    expect(DataSource::query()->whereKey($source->id)->exists())->toBeFalse()
        ->and(DataSource::query()->withTrashed()->whereKey($source->id)->exists())->toBeTrue()
        // "Where did this record come from" outlives the integration.
        ->and(IntegrationEvent::query()->whereKey($event->id)->exists())->toBeTrue();
});

// -- The event log -------------------------------------------------------------

test('an event is stamped with when it arrived, without being told', function () {
    Carbon::setTestNow('2026-09-13 09:30:00');
    $source = DataSource::factory()->create();

    $event = IntegrationEvent::query()->create([
        'data_source_id' => $source->id,
        'payload' => '{"id":1}',
    ]);

    expect($event->received_at->format('Y-m-d H:i:s'))->toBe('2026-09-13 09:30:00')
        ->and($event->uuid)->not->toBeEmpty()
        ->and($event->status())->toBe(IntegrationEventStatus::Received);
});

test('processing a delivery does not move when it arrived', function () {
    // MySQL and MariaDB hand the first NOT NULL TIMESTAMP column an implicit
    // ON UPDATE CURRENT_TIMESTAMP, which would silently rewrite received_at
    // every time the pipeline touched the row. See .ai/rules/migrations.md.
    Carbon::setTestNow('2026-09-13 09:30:00');
    $event = IntegrationEvent::factory()->create();

    Carbon::setTestNow('2026-09-13 11:00:00');
    $event->forceFill(['status' => IntegrationEventStatus::Processed->value])->save();

    expect($event->fresh()->received_at->format('Y-m-d H:i:s'))->toBe('2026-09-13 09:30:00');
});

test('received_at is a datetime column with no automatic update clause', function () {
    $columns = Schema::getColumns('integration_events');
    $received = collect($columns)->firstWhere('name', 'received_at');

    expect($received)->not->toBeNull()
        ->and(strtolower((string) $received['type_name']))->toBe('datetime');
});

test('the raw payload is stored as the bytes that arrived', function () {
    $source = DataSource::factory()->create();
    // Key order and spacing are part of what the signature covers, so a column
    // that reserialised them would make it unverifiable afterwards.
    $body = '{"b":2,  "a":1}';

    $event = IntegrationEvent::query()->create([
        'data_source_id' => $source->id,
        'payload' => $body,
    ]);

    expect($event->fresh()->payload)->toBe($body)
        ->and($event->payloadBytes())->toBe(strlen($body));
});

test('nothing that captures a delivery may declare what became of it', function () {
    $source = DataSource::factory()->create();

    $event = IntegrationEvent::query()->create([
        'data_source_id' => $source->id,
        'payload' => '{}',
        'status' => IntegrationEventStatus::Processed->value,
        'outcome' => 'created',
        'record_type' => Lead::class,
        'record_id' => 99,
    ]);

    // The processing pipeline owns these, the way the complete and cancel
    // actions own an activity's status.
    expect($event->status())->toBe(IntegrationEventStatus::Received)
        ->and($event->outcome)->toBeNull()
        ->and($event->record_id)->toBeNull();
});

test('an event keeps the sandbox decision that applied when it arrived', function () {
    $source = DataSource::factory()->sandbox()->create();
    $event = IntegrationEvent::factory()->forSource($source)->create();

    $source->update(['is_sandbox' => false]);

    // Copied, not joined: every event from before the switch would otherwise
    // read as though it had written a record.
    expect($event->fresh()->is_sandbox)->toBeTrue();
});

test('the log reads newest first, with a stable tiebreaker', function () {
    $source = DataSource::factory()->create();
    $older = IntegrationEvent::factory()->forSource($source)->receivedAt('2026-09-13 09:00:00')->create();
    // Same second: without the id tiebreaker these come back in whatever order
    // the engine chose, and paging repeats or skips rows.
    $first = IntegrationEvent::factory()->forSource($source)->receivedAt('2026-09-13 10:00:00')->create();
    $second = IntegrationEvent::factory()->forSource($source)->receivedAt('2026-09-13 10:00:00')->create();

    expect(IntegrationEvent::query()->latestFirst()->pluck('id')->all())
        ->toBe([$second->id, $first->id, $older->id]);
});

test('the log can be narrowed to failures', function () {
    $source = DataSource::factory()->create();
    IntegrationEvent::factory()->forSource($source)->count(2)->create();
    $broken = IntegrationEvent::factory()->forSource($source)->failed()->create();

    expect(IntegrationEvent::query()->failed()->pluck('id')->all())->toBe([$broken->id])
        ->and($broken->status()->isSettled())->toBeTrue()
        // Skipped is settled but is not a failure: a source that filters hard
        // discards most of what it is sent, and calling that an error would
        // bury the real ones.
        ->and(IntegrationEventStatus::Skipped->isSettled())->toBeTrue();
});

test('force-deleting a source takes its event log with it', function () {
    $source = DataSource::factory()->create();
    IntegrationEvent::factory()->forSource($source)->count(3)->create();

    $source->forceDelete();

    expect(IntegrationEvent::query()->where('data_source_id', $source->id)->count())->toBe(0);
});

// -- Access --------------------------------------------------------------------

test('both integration permissions are declared in the catalogue', function () {
    // The roles matrix and the seeder both read the catalogue, so a permission
    // a policy checks but the catalogue does not list can never be granted.
    expect(PermissionCatalogue::has('integrations.view'))->toBeTrue()
        ->and(PermissionCatalogue::has('integrations.manage'))->toBeTrue();
});

test('reading the list and changing it are different permissions', function () {
    $viewer = ingestionUser(['integrations.view']);
    $admin = ingestionAdmin();
    $source = DataSource::factory()->create();

    expect($viewer->can('viewAny', DataSource::class))->toBeTrue()
        ->and($viewer->can('view', $source))->toBeTrue()
        // Deciding what may enter the database is a different job from being
        // able to answer "did that come through".
        ->and($viewer->can('create', DataSource::class))->toBeFalse()
        ->and($viewer->can('update', $source))->toBeFalse()
        ->and($viewer->can('delete', $source))->toBeFalse();

    expect($admin->can('create', DataSource::class))->toBeTrue()
        ->and($admin->can('update', $source))->toBeTrue()
        ->and($admin->can('delete', $source))->toBeTrue();
});

test('somebody with neither permission cannot reach the module at all', function () {
    $outsider = User::factory()->create();
    $source = DataSource::factory()->create();

    expect($outsider->can('viewAny', DataSource::class))->toBeFalse()
        ->and($outsider->can('view', $source))->toBeFalse()
        ->and($outsider->can('create', DataSource::class))->toBeFalse()
        ->and($outsider->can('update', $source))->toBeFalse()
        ->and($outsider->can('delete', $source))->toBeFalse();
});

// -- Audit ---------------------------------------------------------------------

test('changing a source is recorded in the audit trail', function () {
    $source = createDataSource();

    $source->update(['is_active' => false]);

    $entry = AuditEntry::query()
        ->where('subject_type', $source->getMorphClass())
        ->where('subject_id', $source->id)
        ->where('event', 'updated')
        ->first();

    expect($entry)->not->toBeNull()
        ->and(DataSource::activitySubjectLabel())->toBe('Data source');
});

test('the audit allowlist is explicit, so a secret column added later records nothing', function () {
    $source = DataSource::factory()->create();
    $logged = (new ReflectionClass(DataSource::class))->getMethod('activityAttributes');
    $logged->setAccessible(true);

    /** @var array<int, string> $attributes */
    $attributes = $logged->invoke($source);

    // 8.2 adds hashed secret columns to this table. Opt-in means they record
    // nothing until somebody lists them here, and they never should be.
    expect($attributes)->toContain('is_active')
        ->and($attributes)->toContain('target_module')
        ->and($attributes)->not->toContain('uuid');

    foreach ($attributes as $attribute) {
        expect($attribute)->not->toContain('secret');
    }
});
