<?php

use App\Domain\Ingestion\Actions\ReplayIntegrationEventAction;
use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\IntegrationEventExportSource;
use App\Domain\Ingestion\IntegrationEventFields;
use App\Domain\Ingestion\IntegrationHealth;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Domain\Leads\Models\Lead;
use App\Domain\Notifications\NotificationEventRegistry;
use App\Domain\Shared\Enums\ExportFormat;
use App\Domain\Shared\Enums\FilterOperator;
use App\Domain\Shared\Enums\ViewMode;
use App\Domain\Shared\Exports\ExportRequest;
use App\Livewire\Settings\IntegrationLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
});

// -- The screen -------------------------------------------------------------------

test('the log lists every delivery', function () {
    $source = DataSource::factory()->into('leads')->create(['name' => 'Delivery tool']);
    IntegrationEvent::factory()->forSource($source)->count(3)->create();

    Livewire::actingAs(ingestionAdmin())
        ->test(IntegrationLog::class)
        ->assertOk()
        ->assertSee('Delivery tool');
});

test('the log needs the view permission', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(IntegrationLog::class)
        ->assertForbidden();
});

test('the route resolves', function () {
    $this->actingAs(ingestionAdmin())->get(route('settings.integration-log'))->assertOk();
    $this->actingAs(User::factory()->create())->get(route('settings.integration-log'))->assertForbidden();
});

test('it offers all four view modes', function () {
    $screen = Livewire::actingAs(ingestionAdmin())->test(IntegrationLog::class)->instance();

    expect(array_map(fn (ViewMode $mode) => $mode->value, $screen->availableViewModes()))
        ->toContain('table', 'kanban', 'grid', 'list');
});

test('the board groups by status and is read only', function () {
    $source = DataSource::factory()->into('leads')->create();
    $event = IntegrationEvent::factory()->forSource($source)->failed()->create();

    $screen = Livewire::actingAs(ingestionAdmin())
        ->test(IntegrationLog::class)
        ->call('setViewMode', ViewMode::Kanban->value);

    expect($screen->instance()->dataViewKanbanField())->toBe('status');

    // A card dropped into "Processed" has not been processed, and saying it has
    // would make the log lie about what happened.
    $screen->call('moveCard', $event->id, IntegrationEventStatus::Processed->value)
        ->assertReturned(false);

    expect($event->fresh()->status())->toBe(IntegrationEventStatus::Failed);
});

// -- Filtering --------------------------------------------------------------------

test('the log narrows to one source', function () {
    $wanted = DataSource::factory()->into('leads')->create(['name' => 'Wanted']);
    $other = DataSource::factory()->into('leads')->create(['name' => 'Other']);
    IntegrationEvent::factory()->forSource($wanted)->create(['external_id' => 'keep-me']);
    IntegrationEvent::factory()->forSource($other)->create(['external_id' => 'drop-me']);

    $rows = Livewire::actingAs(ingestionAdmin())
        ->test(IntegrationLog::class)
        ->set('sourceId', (string) $wanted->id)
        ->instance()
        ->rows();

    expect($rows->pluck('external_id')->all())->toBe(['keep-me']);
});

test('each quick filter narrows to what it says', function (string $chip, string $expected) {
    Carbon::setTestNow('2026-09-13 12:00:00');
    $source = DataSource::factory()->into('leads')->create();

    IntegrationEvent::factory()->forSource($source)->failed()->create(['external_id' => 'broken']);
    IntegrationEvent::factory()->forSource($source)->withStatus(IntegrationEventStatus::Processed)
        ->create(['external_id' => 'fine', 'record_type' => Lead::class, 'record_id' => 1]);
    IntegrationEvent::factory()->forSource($source)->withStatus(IntegrationEventStatus::Skipped)
        ->create(['external_id' => 'nothing-made']);
    IntegrationEvent::factory()->forSource($source)->create(['external_id' => 'sandboxed', 'is_sandbox' => true]);

    $rows = Livewire::actingAs(ingestionAdmin())
        ->test(IntegrationLog::class)
        ->call('setQuickFilter', $chip)
        ->instance()
        ->rows();

    expect($rows->pluck('external_id')->all())->toContain($expected);
})->with([
    'failed' => ['failed', 'broken'],
    'made nothing' => ['unmapped', 'nothing-made'],
    'sandbox' => ['sandbox', 'sandboxed'],
]);

test('the search reaches into the payload', function () {
    $source = DataSource::factory()->into('leads')->create();
    IntegrationEvent::factory()->forSource($source)->create([
        'payload' => json_encode(['contact' => ['email' => 'dara@acme.test']]),
        'external_id' => 'wanted',
    ]);
    IntegrationEvent::factory()->forSource($source)->create([
        'payload' => json_encode(['contact' => ['email' => 'someone@else.test']]),
        'external_id' => 'other',
    ]);

    // Somebody asking "did that come through" has an email address, not an
    // event id, and the body is the only place it appears.
    $rows = Livewire::actingAs(ingestionAdmin())
        ->test(IntegrationLog::class)
        ->set('search', 'dara@acme.test')
        ->instance()
        ->rows();

    expect($rows->pluck('external_id')->all())->toBe(['wanted']);
});

test('the model scope and the list search return the same rows', function () {
    $source = DataSource::factory()->into('leads')->create();
    IntegrationEvent::factory()->forSource($source)->count(2)->create(['payload' => '{"who":"dara"}']);
    IntegrationEvent::factory()->forSource($source)->create(['payload' => '{"who":"sam"}']);

    $listed = Livewire::actingAs(ingestionAdmin())
        ->test(IntegrationLog::class)
        ->set('search', 'dara')
        ->instance()
        ->rows()
        ->pluck('id')->sort()->values()->all();

    $scoped = IntegrationEvent::query()->search('dara')->pluck('id')->sort()->values()->all();

    // A queued export rebuilds from the model scope, so the two disagreeing
    // would mean an export containing rows the list never showed.
    expect($listed)->toBe($scoped);
});

test('the filter builder narrows by a declared field', function () {
    $source = DataSource::factory()->into('leads')->create();
    IntegrationEvent::factory()->forSource($source)->failed()->create(['external_id' => 'broken']);
    IntegrationEvent::factory()->forSource($source)->create(['external_id' => 'fine']);

    $rows = Livewire::actingAs(ingestionAdmin())
        ->test(IntegrationLog::class)
        ->set('filters.conditions', [[
            'field' => 'status',
            'operator' => FilterOperator::Equals->value,
            'value' => IntegrationEventStatus::Failed->value,
            'value2' => null,
        ]])
        ->instance()
        ->rows();

    expect($rows->pluck('external_id')->all())->toBe(['broken']);
});

test('every filter field is a real column', function () {
    $columns = Schema::getColumnListing('integration_events');

    foreach (IntegrationEventFields::filters() as $field) {
        expect($columns)->toContain($field->column());
    }
});

// -- The inspector -----------------------------------------------------------------

test('a delivery can be opened, and shows what arrived and what it made of it', function () {
    $source = ingestionLeadSource();
    $event = ingestionDeliver($source, ingestionTaskPayload());

    Livewire::actingAs(ingestionAdmin())
        ->test(IntegrationLog::class)
        ->call('inspect', $event->id)
        ->assertSee('dara@acme.test')
        ->assertSee('Okafor');
});

test('the payload is laid out for reading without the stored bytes being touched', function () {
    $source = DataSource::factory()->into('leads')->create();
    $compact = '{"a":1,"b":{"c":2}}';
    $event = IntegrationEvent::factory()->forSource($source)->create(['payload' => $compact]);

    $screen = Livewire::actingAs(ingestionAdmin())
        ->test(IntegrationLog::class)
        ->call('inspect', $event->id);

    // Re-encoded only for display. The stored bytes are what the signature
    // covered and what a replay sends.
    expect($screen->instance()->prettyPayload())->toContain("\n")
        ->and($event->fresh()->payload)->toBe($compact);
});

test('a payload that is not JSON is shown as it arrived', function () {
    $source = DataSource::factory()->into('leads')->create();
    $event = IntegrationEvent::factory()->forSource($source)->create(['payload' => 'not json at all']);

    $screen = Livewire::actingAs(ingestionAdmin())
        ->test(IntegrationLog::class)
        ->call('inspect', $event->id);

    expect($screen->instance()->prettyPayload())->toBe('not json at all');
});

test('the inspector closes', function () {
    $source = DataSource::factory()->into('leads')->create();
    $event = IntegrationEvent::factory()->forSource($source)->create();

    Livewire::actingAs(ingestionAdmin())
        ->test(IntegrationLog::class)
        ->call('inspect', $event->id)
        ->assertSet('inspectingId', $event->id)
        ->call('stopInspecting')
        ->assertSet('inspectingId', null);
});

// -- Replay --------------------------------------------------------------------------

test('a replay after a mapping fix creates the record the first attempt could not', function () {
    $source = ingestionLeadSource(['external_id_path' => 'id']);
    // A mapping naming a field that exists, fed a value the module refuses.
    $event = ingestionDeliver($source, ingestionTaskPayload(['contact' => ['email' => 'not-an-email']]));

    expect($event->status())->toBe(IntegrationEventStatus::Failed)
        ->and(Lead::query()->count())->toBe(0);

    // Somebody corrects the source system's payload path — here, simply
    // stopping the bad value being mapped at all.
    $source->mappings()->where('target_field', 'email')->delete();

    app(ReplayIntegrationEventAction::class)($event->fresh());

    // The body was kept, so the delivery can go through the corrected rules
    // rather than being asked for again from a system that may not have it.
    expect($event->fresh()->status())->toBe(IntegrationEventStatus::Processed)
        ->and(Lead::query()->count())->toBe(1);
});

test('a replay updates the record it already made rather than making a second', function () {
    $source = ingestionLeadSource(['external_id_path' => 'id']);
    $event = ingestionDeliver($source, ingestionTaskPayload());

    expect(Lead::query()->count())->toBe(1);

    app(ReplayIntegrationEventAction::class)($event->fresh());

    expect(Lead::query()->count())->toBe(1)
        ->and($event->fresh()->outcome)->toBe('updated');
});

test('a replay resets the row rather than making a second one', function () {
    $source = ingestionLeadSource();
    $event = ingestionDeliver($source, ingestionTaskPayload());
    $before = IntegrationEvent::query()->count();

    app(ReplayIntegrationEventAction::class)($event->fresh());

    // "How many did they send us" is a question the log has to answer.
    expect(IntegrationEvent::query()->count())->toBe($before);
});

test('a replay counts as another attempt and clears the last outcome', function () {
    $source = ingestionLeadSource();
    $event = ingestionDeliver($source, ingestionTaskPayload(['contact' => ['email' => 'not-an-email']]));

    expect($event->attempts)->toBe(1);

    app(ReplayIntegrationEventAction::class)($event->fresh());

    // attempts counts trips through the pipeline, and a replay is one of them.
    expect($event->fresh()->attempts)->toBe(2);
});

test('the screen replays one delivery', function () {
    $source = ingestionLeadSource(['external_id_path' => 'id']);
    $event = ingestionDeliver($source, ingestionTaskPayload(['contact' => ['email' => 'not-an-email']]));

    $source->mappings()->where('target_field', 'email')->delete();

    Livewire::actingAs(ingestionAdmin())
        ->test(IntegrationLog::class)
        ->call('replay', $event->id);

    expect(Lead::query()->count())->toBe(1);
});

test('the screen replays a selection', function () {
    $source = ingestionLeadSource(['external_id_path' => 'id']);
    $first = ingestionDeliver($source, ingestionTaskPayload(['id' => 'a', 'contact' => ['email' => 'bad']]));
    $second = ingestionDeliver($source->fresh(), ingestionTaskPayload(['id' => 'b', 'contact' => ['email' => 'also-bad']]));

    $source->mappings()->where('target_field', 'email')->delete();

    Livewire::actingAs(ingestionAdmin())
        ->test(IntegrationLog::class)
        ->call('toggleSelection', $first->id)
        ->call('toggleSelection', $second->id)
        ->call('replaySelected');

    expect(Lead::query()->count())->toBe(2);
});

test('replay needs more than the view permission', function () {
    $source = ingestionLeadSource();
    $event = ingestionDeliver($source, ingestionTaskPayload(['contact' => ['email' => 'not-an-email']]));

    $screen = Livewire::actingAs(ingestionUser(['integrations.view']))->test(IntegrationLog::class);

    expect($screen->instance()->canReplay())->toBeFalse();

    $screen->call('replay', $event->id)->assertForbidden();
});

// -- Health ---------------------------------------------------------------------------

test('consecutive failures are counted back from the most recent', function () {
    $source = DataSource::factory()->into('leads')->create();

    IntegrationEvent::factory()->forSource($source)->withStatus(IntegrationEventStatus::Processed)
        ->receivedAt('2026-09-13 09:00:00')->create();
    IntegrationEvent::factory()->forSource($source)->failed()->receivedAt('2026-09-13 10:00:00')->create();
    IntegrationEvent::factory()->forSource($source)->failed()->receivedAt('2026-09-13 11:00:00')->create();

    expect(IntegrationHealth::consecutiveFailures($source))->toBe(2);
});

test('one success ends the run', function () {
    $source = DataSource::factory()->into('leads')->create();

    IntegrationEvent::factory()->forSource($source)->failed()->receivedAt('2026-09-13 09:00:00')->create();
    IntegrationEvent::factory()->forSource($source)->failed()->receivedAt('2026-09-13 10:00:00')->create();
    IntegrationEvent::factory()->forSource($source)->withStatus(IntegrationEventStatus::Processed)
        ->receivedAt('2026-09-13 11:00:00')->create();

    // A source that has failed forty times and is working now is working.
    expect(IntegrationHealth::consecutiveFailures($source))->toBe(0);
});

test('the summary counts the last day by status', function () {
    Carbon::setTestNow('2026-09-13 12:00:00');
    $source = DataSource::factory()->into('leads')->create();

    IntegrationEvent::factory()->forSource($source)->withStatus(IntegrationEventStatus::Processed)
        ->receivedAt('2026-09-13 09:00:00')->count(3)->create();
    IntegrationEvent::factory()->forSource($source)->failed()->receivedAt('2026-09-13 10:00:00')->create();
    // Older than the window.
    IntegrationEvent::factory()->forSource($source)->failed()->receivedAt('2026-09-01 10:00:00')->create();

    $stats = IntegrationHealth::summarise($source);

    expect($stats['processed'])->toBe(3)
        ->and($stats['failed'])->toBe(1)
        ->and($stats['received'])->toBe(4);
});

test('the dashboard shows every source', function () {
    DataSource::factory()->into('leads')->create(['name' => 'First one']);
    DataSource::factory()->into('leads')->create(['name' => 'Second one']);

    Livewire::actingAs(ingestionAdmin())
        ->test(IntegrationLog::class)
        ->assertSee('First one')
        ->assertSee('Second one');
});

// -- Alerts ---------------------------------------------------------------------------

test('the alert event is registered', function () {
    expect(NotificationEventRegistry::find('integration.failing'))->not->toBeNull();
});

test('an alert fires once the run reaches the threshold', function () {
    $admin = ingestionAdmin();
    $admin->forceFill(['email_verified_at' => now()])->save();

    $source = ingestionLeadSource();

    // One short of the threshold: nothing yet.
    for ($i = 1; $i < IntegrationHealth::ALERT_AFTER; $i++) {
        ingestionDeliver($source->fresh(), ingestionTaskPayload(['contact' => ['email' => 'bad-'.$i]]));
    }

    expect($admin->unreadNotifications()->count())->toBe(0);

    ingestionDeliver($source->fresh(), ingestionTaskPayload(['contact' => ['email' => 'bad-final']]));

    expect($admin->unreadNotifications()->count())->toBe(1);
});

test('a thoroughly broken source sends one alert, not one per delivery', function () {
    $admin = ingestionAdmin();
    $admin->forceFill(['email_verified_at' => now()])->save();

    $source = ingestionLeadSource();

    for ($i = 1; $i <= IntegrationHealth::ALERT_AFTER + 4; $i++) {
        ingestionDeliver($source->fresh(), ingestionTaskPayload(['contact' => ['email' => 'bad-'.$i]]));
    }

    // Alerting on every failure past the threshold would train people to ignore
    // the alerts.
    expect($admin->unreadNotifications()->count())->toBe(1);
});

test('a success resets the run, so the next outage alerts again', function () {
    $admin = ingestionAdmin();
    $admin->forceFill(['email_verified_at' => now()])->save();

    $source = ingestionLeadSource();

    for ($i = 1; $i <= IntegrationHealth::ALERT_AFTER; $i++) {
        ingestionDeliver($source->fresh(), ingestionTaskPayload(['contact' => ['email' => 'bad-'.$i]]));
    }

    ingestionDeliver($source->fresh(), ingestionTaskPayload(['contact' => ['email' => 'fine@acme.test']]));

    for ($i = 1; $i <= IntegrationHealth::ALERT_AFTER; $i++) {
        ingestionDeliver($source->fresh(), ingestionTaskPayload(['contact' => ['email' => 'bad-again-'.$i]]));
    }

    expect($admin->unreadNotifications()->count())->toBe(2);
});

test('a single failure tells nobody', function () {
    $admin = ingestionAdmin();
    $admin->forceFill(['email_verified_at' => now()])->save();

    $source = ingestionLeadSource();
    ingestionDeliver($source, ingestionTaskPayload(['contact' => ['email' => 'not-an-email']]));

    // Every integration fails occasionally. Alerting on each would be noise.
    expect($admin->unreadNotifications()->count())->toBe(0)
        ->and(IntegrationHealth::ALERT_AFTER)->toBeGreaterThan(1);
});

// -- Export -----------------------------------------------------------------------------

test('the log exports without the payloads', function () {
    $source = DataSource::factory()->into('leads')->create(['name' => 'Delivery tool']);
    $event = IntegrationEvent::factory()->forSource($source)->failed()
        ->create(['payload' => '{"secret":"customer data"}', 'external_id' => 'TASK-1']);

    $request = new ExportRequest(
        source: IntegrationEventExportSource::class,
        format: ExportFormat::Csv,
        module: 'integration-events',
        columns: ['received_at' => 'Received', 'source' => 'Source', 'status' => 'Status', 'payload' => 'Payload'],
        userId: ingestionAdmin()->id,
    );

    $row = app(IntegrationEventExportSource::class)->exportRow($event, $request);

    // A spreadsheet of raw bodies is customer data leaving by the easiest
    // possible route. Somebody who needs one body reads it on the screen.
    expect($row[1])->toBe('Delivery tool')
        ->and($row[2])->toBe('Failed')
        ->and($row[3])->toBeNull()
        ->and(implode(' ', array_map('strval', $row)))->not->toContain('customer data');
});

test('the export is offered to anybody who may read the log', function () {
    expect(Livewire::actingAs(ingestionUser(['integrations.view']))->test(IntegrationLog::class)
        ->instance()->dataViewExportSource())->not->toBeNull();
});
