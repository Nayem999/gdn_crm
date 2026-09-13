<?php

use App\Domain\Ingestion\Actions\SyncDataSourceAction;
use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\Enums\PullAuth;
use App\Domain\Ingestion\Enums\PullSchedule;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\DataSourceMapping;
use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Domain\Leads\Models\Lead;
use App\Livewire\Settings\DataSources;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * A pull source pointed at a fake endpoint, mapped and ready to write leads.
 */
function pullSource(array $attributes = []): DataSource
{
    $owner = User::factory()->create();

    $source = DataSource::factory()
        ->fetchingFrom('https://their-system.test/api/tasks', $attributes)
        ->into('leads')
        ->create(['default_owner_id' => $owner->id]);

    DataSourceMapping::factory()->forSource($source)->mapping('first_name', 'first_name')->create();
    DataSourceMapping::factory()->forSource($source)->mapping('surname', 'last_name')->create();
    DataSourceMapping::factory()->forSource($source)->mapping('email', 'email')->create();

    return $source->fresh();
}

/**
 * One record as their system writes it.
 *
 * @return array<string, mixed>
 */
function pullRecord(int $n, ?string $updatedAt = null): array
{
    return [
        'id' => 'TASK-'.$n,
        'first_name' => 'Person',
        'surname' => 'Number'.$n,
        'email' => 'person'.$n.'@their-system.test',
        'updated_at' => $updatedAt ?? '2026-09-13T10:0'.min($n, 9).':00Z',
    ];
}

function syncPull(DataSource $source): array
{
    return app(SyncDataSourceAction::class)($source);
}

beforeEach(function () {
    Cache::flush();
});

// -- Pagination ---------------------------------------------------------------------

test('a paginated fetch imports every page', function () {
    $source = pullSource();

    // Two full pages then a short one, which is how every paginated API ends.
    Http::fake([
        'their-system.test/api/tasks?page=1*' => Http::response(['data' => [pullRecord(1), pullRecord(2)]]),
        'their-system.test/api/tasks?page=2*' => Http::response(['data' => [pullRecord(3), pullRecord(4)]]),
        'their-system.test/api/tasks?page=3*' => Http::response(['data' => [pullRecord(5)]]),
    ]);

    $summary = syncPull($source);

    expect($summary['ok'])->toBeTrue()
        ->and($summary['records'])->toBe(5)
        ->and($summary['pages'])->toBe(3)
        ->and(IntegrationEvent::query()->count())->toBe(5)
        ->and(Lead::query()->count())->toBe(5);
});

test('a short page ends the run without another request', function () {
    $source = pullSource();

    Http::fake([
        'their-system.test/*' => Http::response(['data' => [pullRecord(1)]]),
    ]);

    $summary = syncPull($source);

    // Page size is 2 and one came back, so there is nothing after it. Asking
    // anyway costs a request every single run.
    expect($summary['pages'])->toBe(1)
        ->and($summary['records'])->toBe(1);
});

test('an empty first page is a run that found nothing', function () {
    $source = pullSource();

    Http::fake(['their-system.test/*' => Http::response(['data' => []])]);

    $summary = syncPull($source);

    expect($summary['ok'])->toBeTrue()
        ->and($summary['records'])->toBe(0)
        ->and(IntegrationEvent::query()->count())->toBe(0);
});

test('the records are found inside their envelope', function (string $path, array $body) {
    $source = pullSource(['pull_records_path' => $path]);

    Http::fake(['their-system.test/*' => Http::response($body)]);

    expect(syncPull($source)['records'])->toBe(1);
})->with([
    'a named key' => ['data', ['data' => [['id' => 1, 'first_name' => 'A', 'surname' => 'B', 'email' => 'a@b.test']]]],
    'nested' => ['result.items', ['result' => ['items' => [['id' => 1, 'first_name' => 'A', 'surname' => 'B', 'email' => 'a@b.test']]]]],
    'a bare list' => ['', [['id' => 1, 'first_name' => 'A', 'surname' => 'B', 'email' => 'a@b.test']]],
]);

test('an envelope that is not there yields nothing rather than breaking', function () {
    $source = pullSource(['pull_records_path' => 'nowhere.at.all']);

    Http::fake(['their-system.test/*' => Http::response(['data' => [pullRecord(1)]])]);

    expect(syncPull($source)['records'])->toBe(0);
});

test('runaway pagination is bounded', function () {
    $source = pullSource();

    // Their code, answering "there is always another page" for ever.
    Http::fake(['their-system.test/*' => Http::response(['data' => [pullRecord(1), pullRecord(2)]])]);

    $summary = syncPull($source);

    // A sync that never finishes blocks every later one.
    expect($summary['pages'])->toBe(SyncDataSourceAction::MAX_PAGES);
});

// -- The cursor ------------------------------------------------------------------------

test('the cursor advances to the furthest value seen', function () {
    $source = pullSource(['pull_cursor_param' => 'updated_since', 'pull_cursor_path' => 'updated_at']);

    Http::fake([
        'their-system.test/*' => Http::response(['data' => [
            pullRecord(1, '2026-09-13T10:00:00Z'),
            pullRecord(2, '2026-09-13T12:00:00Z'),
        ]]),
    ]);

    syncPull($source);

    expect($source->fresh()->pull_cursor)->toBe('2026-09-13T12:00:00Z');
});

test('a second run asks only for what has changed since', function () {
    $source = pullSource(['pull_cursor_param' => 'updated_since', 'pull_cursor_path' => 'updated_at']);

    Http::fake([
        'their-system.test/*' => Http::response(['data' => [pullRecord(1, '2026-09-13T10:00:00Z')]]),
    ]);

    syncPull($source);
    syncPull($source->fresh());

    // The first request carries no cursor — a first run fetches the backlog —
    // and every one after it carries the mark.
    Http::assertSent(fn ($request) => ! str_contains($request->url(), 'updated_since'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'updated_since=2026-09-13T10%3A00%3A00Z')
        || str_contains($request->url(), 'updated_since=2026-09-13T10:00:00Z'));
});

test('a second run imports only the new records', function () {
    $source = pullSource([
        'pull_cursor_param' => 'updated_since',
        'pull_cursor_path' => 'updated_at',
        'external_id_path' => 'id',
    ]);

    // A sequence, not two Http::fake() calls: a second fake **appends** its
    // stub, and the first registered one keeps matching — so the second run
    // would be handed the first run's answer and silently update instead of
    // creating.
    Http::fakeSequence()
        ->push(['data' => [pullRecord(1, '2026-09-13T10:00:00Z')]])
        ->push(['data' => [pullRecord(2, '2026-09-13T11:00:00Z')]]);

    syncPull($source);

    expect(Lead::query()->count())->toBe(1);

    // Their side now answers with only what changed after the mark.
    syncPull($source->fresh());

    expect(Lead::query()->count())->toBe(2)
        ->and($source->fresh()->pull_cursor)->toBe('2026-09-13T11:00:00Z');
});

test('the cursor is written once, at the end', function () {
    $source = pullSource(['pull_cursor_param' => 'since', 'pull_cursor_path' => 'updated_at']);

    // Page one succeeds, page two is their outage.
    Http::fakeSequence()
        ->push(['data' => [pullRecord(1, '2026-09-13T10:00:00Z'), pullRecord(2, '2026-09-13T11:00:00Z')]])
        ->push('', 500);

    $summary = syncPull($source);

    // Advancing per record would leave the mark past records a failed run never
    // delivered, and they would never be fetched again.
    expect($summary['ok'])->toBeFalse()
        ->and($source->fresh()->pull_cursor)->toBeNull();
});

test('a source with no cursor configured fetches everything every time', function () {
    $source = pullSource();

    Http::fake(['their-system.test/*' => Http::response(['data' => [pullRecord(1)]])]);

    syncPull($source);

    expect($source->fresh()->pull_cursor)->toBeNull();
});

// -- Authentication ----------------------------------------------------------------------

test('each way of signing in sends what it should', function (PullAuth $auth, string $name, string $header) {
    $source = pullSource([
        'pull_auth_type' => $auth->value,
        'pull_auth_name' => $name,
        'pull_auth_secret' => 'sekrit',
    ]);

    Http::fake(['their-system.test/*' => Http::response(['data' => []])]);

    syncPull($source);

    Http::assertSent(fn ($request) => $request->hasHeader($header));
})->with([
    'bearer' => [PullAuth::Bearer, '', 'Authorization'],
    'basic' => [PullAuth::Basic, 'us', 'Authorization'],
    'their own header' => [PullAuth::Header, 'X-Their-Key', 'X-Their-Key'],
]);

test('the credential is encrypted at rest and never serialised', function () {
    $source = pullSource(['pull_auth_type' => PullAuth::Bearer->value, 'pull_auth_secret' => 'sekrit']);

    $row = DB::table('data_sources')->where('id', $source->id)->first();

    expect((string) $row->pull_auth_secret)->not->toContain('sekrit')
        ->and($source->fresh()->pull_auth_secret)->toBe('sekrit')
        ->and(json_encode($source->fresh()->toArray()))->not->toContain('sekrit');
});

// -- Failure ------------------------------------------------------------------------------

test('their error is recorded rather than thrown', function () {
    $source = pullSource();

    Http::fake(['their-system.test/*' => Http::response('', 503)]);

    $summary = syncPull($source);

    expect($summary['ok'])->toBeFalse()
        ->and($summary['error'])->toContain('503')
        ->and($source->fresh()->last_sync_summary['ok'])->toBeFalse()
        // Recorded even on failure, so the screen can say when it last tried.
        ->and($source->fresh()->last_synced_at)->not->toBeNull();
});

test('their error body never reaches the summary', function () {
    $source = pullSource();

    // Their error page can contain anything, including our own token echoed
    // back at us.
    Http::fake(['their-system.test/*' => Http::response('token=sekrit-value leaked here', 500)]);

    $summary = syncPull($source);

    expect($summary['error'])->not->toContain('sekrit-value');
});

test('an answer that is not JSON is a failure, not an empty run', function () {
    $source = pullSource();

    Http::fake(['their-system.test/*' => Http::response('<html>maintenance</html>', 200)]);

    expect(syncPull($source)['ok'])->toBeFalse();
});

test('a url pointing inside our own network is refused', function () {
    $source = pullSource(['pull_url' => 'http://127.0.0.1/internal']);

    Http::fake();

    $summary = syncPull($source);

    // An administrator typing a URL is still somebody typing a URL, and this
    // one is fetched with the server's own network access.
    expect($summary['ok'])->toBeFalse();
    Http::assertNothingSent();
});

test('a push source cannot be synced', function () {
    $source = DataSource::factory()->into('leads')->create();

    expect(syncPull($source)['ok'])->toBeFalse();
});

test('a switched-off source is not fetched', function () {
    $source = pullSource();
    $source->update(['is_active' => false]);

    Http::fake();

    expect(syncPull($source->fresh())['ok'])->toBeFalse();
    Http::assertNothingSent();
});

// -- What a pulled record becomes ------------------------------------------------------------

test('a pulled record goes through the same pipeline a pushed one does', function () {
    $source = pullSource();

    Http::fake(['their-system.test/*' => Http::response(['data' => [pullRecord(1)]])]);

    syncPull($source);

    $event = IntegrationEvent::query()->firstOrFail();
    $lead = Lead::query()->firstOrFail();

    expect($event->status())->toBe(IntegrationEventStatus::Processed)
        ->and($event->record_id)->toBe($lead->id)
        // Nothing signed it — we fetched it. Claiming otherwise would make the
        // log assert a guarantee that was never made.
        ->and($event->signature_verified)->toBeFalse()
        ->and($lead->email)->toBe('person1@their-system.test');
});

test('a sandbox pull source captures and writes nothing', function () {
    $source = pullSource();
    $source->update(['is_sandbox' => true]);

    Http::fake(['their-system.test/*' => Http::response(['data' => [pullRecord(1)]])]);

    syncPull($source->fresh());

    expect(IntegrationEvent::query()->count())->toBe(1)
        ->and(Lead::query()->count())->toBe(0);
});

// -- The schedule -------------------------------------------------------------------------------

test('a source is due when enough time has passed since it last ran', function (string $schedule, ?string $lastRun, bool $due) {
    Carbon::setTestNow('2026-09-13 12:00:00');

    $source = pullSource(['pull_schedule' => $schedule]);
    $source->forceFill(['last_synced_at' => $lastRun === null ? null : Carbon::parse($lastRun)])->save();

    expect($source->fresh()->isDueForSync())->toBe($due);
})->with([
    'manual is never due' => ['manual', null, false],
    'never run is due' => ['hourly', null, true],
    'an hour ago is due' => ['hourly', '2026-09-13 10:55:00', true],
    'ten minutes ago is not' => ['hourly', '2026-09-13 11:50:00', false],
    'daily, yesterday' => ['daily', '2026-09-12 11:00:00', true],
    'daily, this morning' => ['daily', '2026-09-13 08:00:00', false],
    'quarter hourly' => ['quarter_hourly', '2026-09-13 11:40:00', true],
]);

test('a half-configured source is skipped rather than failing the run', function () {
    Carbon::setTestNow('2026-09-13 12:00:00');
    // No URL: somebody mid-setup, not an error worth waking anybody for.
    $source = DataSource::factory()->pull()->scheduled(PullSchedule::Hourly)->into('leads')->create();

    expect($source->isDueForSync())->toBeFalse();
});

test('the command fetches every source that is due', function () {
    $due = pullSource(['pull_schedule' => PullSchedule::Hourly->value]);
    $notDue = pullSource(['pull_schedule' => PullSchedule::Manual->value]);

    Http::fake(['their-system.test/*' => Http::response(['data' => [pullRecord(1)]])]);

    $this->artisan('ingest:sync')->assertSuccessful();

    expect($due->fresh()->last_synced_at)->not->toBeNull()
        // Manual means manual.
        ->and($notDue->fresh()->last_synced_at)->toBeNull();
});

test('the command can be pointed at one source, schedule or not', function () {
    $source = pullSource(['pull_schedule' => PullSchedule::Manual->value]);

    Http::fake(['their-system.test/*' => Http::response(['data' => [pullRecord(1)]])]);

    $this->artisan('ingest:sync', ['--source' => $source->uuid])->assertSuccessful();

    expect($source->fresh()->last_synced_at)->not->toBeNull();
});

test('one source failing does not stop the others', function () {
    $broken = pullSource(['pull_schedule' => PullSchedule::Hourly->value, 'pull_url' => 'https://broken.test/api']);
    $fine = pullSource(['pull_schedule' => PullSchedule::Hourly->value]);

    Http::fake([
        'broken.test/*' => Http::response('', 500),
        'their-system.test/*' => Http::response(['data' => [pullRecord(1)]]),
    ]);

    $this->artisan('ingest:sync')->assertSuccessful();

    // Their outage is not our outage.
    expect($broken->fresh()->last_sync_summary['ok'])->toBeFalse()
        ->and($fine->fresh()->last_sync_summary['ok'])->toBeTrue();
});

test('the sync is scheduled', function () {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($event) => $event->command ?? '')
        ->implode(' ');

    expect($commands)->toContain('ingest:sync');
});

// -- The screen ------------------------------------------------------------------------------------

test('sync now runs it from the list', function () {
    $source = pullSource();

    Http::fake(['their-system.test/*' => Http::response(['data' => [pullRecord(1)]])]);

    Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->call('syncNow', $source->id);

    expect(Lead::query()->count())->toBe(1)
        ->and($source->fresh()->last_synced_at)->not->toBeNull();
});

test('sync now needs the manage permission', function () {
    $source = pullSource();

    Http::fake();

    Livewire::actingAs(ingestionUser(['integrations.view']))
        ->test(DataSources::class)
        ->call('syncNow', $source->id)
        ->assertForbidden();

    Http::assertNothingSent();
});

test('the form insists on a url for a pull source and not for a push one', function () {
    Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->call('add')
        ->set('name', 'Their system')
        ->set('target_module', 'leads')
        ->set('type', 'pull')
        ->call('save')
        ->assertHasErrors(['pull_url']);

    Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->call('add')
        ->set('name', 'Their system')
        ->set('target_module', 'leads')
        ->set('type', 'push')
        ->call('save')
        ->assertHasNoErrors();
});

test('switching a source to push clears what it fetched with', function () {
    $source = pullSource(['pull_auth_type' => PullAuth::Bearer->value, 'pull_auth_secret' => 'sekrit']);

    Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->call('edit', $source->id)
        ->set('type', 'push')
        ->call('save')
        ->assertHasNoErrors();

    $source->refresh();

    // A URL and a credential on a source that no longer fetches is a credential
    // nobody remembers is there.
    expect($source->pull_url)->toBeNull()
        ->and($source->pull_auth_secret)->toBeNull()
        ->and($source->pullSchedule())->toBe(PullSchedule::Manual);
});

test('a blank credential on submit keeps the one already stored', function () {
    $source = pullSource(['pull_auth_type' => PullAuth::Bearer->value, 'pull_auth_secret' => 'sekrit']);

    Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->call('edit', $source->id)
        ->set('name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect($source->fresh()->pull_auth_secret)->toBe('sekrit');
});

test('the stored credential is never loaded into the form', function () {
    $source = pullSource(['pull_auth_type' => PullAuth::Bearer->value, 'pull_auth_secret' => 'sekrit']);

    Livewire::actingAs(ingestionAdmin())
        ->test(DataSources::class)
        ->call('edit', $source->id)
        ->assertSet('pull_auth_secret', null)
        ->assertDontSee('sekrit');
});
