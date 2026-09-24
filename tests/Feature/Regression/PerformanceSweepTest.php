<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Accounts\Models\Account;
use App\Domain\Activities\Models\Activity;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Models\Lead;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Notifications\NotificationMatrix;
use App\Domain\Products\Models\Product;
use App\Domain\Reports\Models\Report;
use App\Domain\Settings\SettingsManager;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Domain\Support\Models\Ticket;
use App\Domain\Timeline\Models\Note;
use App\Livewire\Accounts\AccountsIndex;
use App\Livewire\Activities\ActivitiesIndex;
use App\Livewire\Contacts\ContactsIndex;
use App\Livewire\Deals\DealShow;
use App\Livewire\Deals\DealsIndex;
use App\Livewire\Leads\LeadsIndex;
use App\Livewire\Products\ProductsIndex;
use App\Livewire\Reports\ReportsIndex;
use App\Livewire\Support\TicketsIndex;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

/**
 * "No N+1 detected", asserted the only way that survives a new module: by
 * counting.
 *
 * A screen is rendered twice, once with a handful of records and once with
 * several times as many. A screen that eager-loads what it draws runs the same
 * number of queries both times; one with an N+1 runs more the second time, and
 * the difference is the bug. Naming a number of queries instead would be a
 * number somebody adjusts until the test passes.
 */

/**
 * Somebody who can see everything, so the sweep measures the query shape rather
 * than an empty result set.
 *
 * @param  array<int, string>  $permissions
 */
function perfUser(array $permissions): User
{
    $role = Role::query()->create([
        'name' => 'Perf '.uniqid(),
        'guard_name' => Guard::getDefaultName(Role::class),
        'data_access_level' => DataAccessLevel::All->value,
    ]);

    $role->syncPermissions(PermissionResolver::models($permissions));

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * How many queries a closure runs.
 */
function queryCount(Closure $work): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $work();

    $count = count(DB::getQueryLog());

    DB::disableQueryLog();

    return $count;
}

/**
 * The list screens, and how to make a record each one shows.
 *
 * @return array<string, array{screen: class-string, permissions: array<int, string>, make: Closure}>
 */
function listScreens(): array
{
    return [
        'leads' => [
            'screen' => LeadsIndex::class,
            'permissions' => ['leads.view'],
            'make' => fn (int $count) => Lead::factory()->count($count)->create(),
        ],
        'contacts' => [
            'screen' => ContactsIndex::class,
            'permissions' => ['contacts.view'],
            'make' => fn (int $count) => Contact::factory()->count($count)->create(),
        ],
        'accounts' => [
            'screen' => AccountsIndex::class,
            'permissions' => ['accounts.view'],
            'make' => fn (int $count) => Account::factory()->count($count)->create(),
        ],
        'deals' => [
            'screen' => DealsIndex::class,
            'permissions' => ['deals.view'],
            'make' => fn (int $count) => Deal::factory()->count($count)->create(),
        ],
        'activities' => [
            'screen' => ActivitiesIndex::class,
            'permissions' => ['activities.view'],
            'make' => fn (int $count) => Activity::factory()->count($count)->create(),
        ],
        'tickets' => [
            'screen' => TicketsIndex::class,
            'permissions' => ['tickets.view'],
            'make' => fn (int $count) => Ticket::factory()->count($count)->create(),
        ],
        'products' => [
            'screen' => ProductsIndex::class,
            'permissions' => ['products.view'],
            'make' => fn (int $count) => Product::factory()->count($count)->create(),
        ],
        'reports' => [
            'screen' => ReportsIndex::class,
            'permissions' => ['reports.view'],
            'make' => fn (int $count) => Report::factory()->count($count)->create(['is_shared' => true]),
        ],
    ];
}

// -- N+1 -----------------------------------------------------------------------

test('a list screen runs the same number of queries however many records it shows', function (string $module) {
    $spec = listScreens()[$module];
    $viewer = perfUser($spec['permissions']);

    ($spec['make'])(3);

    $few = queryCount(function () use ($spec, $viewer) {
        Livewire::actingAs($viewer)->test($spec['screen'])->assertOk();
    });

    ($spec['make'])(12);

    $many = queryCount(function () use ($spec, $viewer) {
        Livewire::actingAs($viewer)->test($spec['screen'])->assertOk();
    });

    // Four times the records. A screen that loads a relation per row would run
    // noticeably more queries the second time; one that eager-loads runs the
    // same number.
    expect($many)->toBeLessThanOrEqual($few + 1, "{$module}: {$few} queries for 3 records, {$many} for 15");
})->with(array_keys(listScreens()));

test('a record page runs a bounded number of queries', function () {
    $viewer = perfUser(['deals.view', 'accounts.view', 'contacts.view', 'activities.view', 'timeline.view']);

    $deal = Deal::factory()->create();

    $first = queryCount(function () use ($viewer, $deal) {
        Livewire::actingAs($viewer)->test(DealShow::class, ['deal' => $deal])->assertOk();
    });

    // The same page with a busier record: notes, documents and activities on it.
    Note::factory()->count(10)->on($deal)->create();

    $second = queryCount(function () use ($viewer, $deal) {
        Livewire::actingAs($viewer)->test(DealShow::class, ['deal' => $deal])->assertOk();
    });

    expect($second)->toBeLessThanOrEqual($first + 2, "{$first} queries when empty, {$second} with ten notes");
});

// -- Indexes -------------------------------------------------------------------

/**
 * Whether a table has an index whose first column is the one named.
 *
 * The first column is what matters: an index on (a, b) helps a query filtering
 * on a, and does nothing for one filtering only on b.
 */
function hasIndexOn(string $table, string $column): bool
{
    foreach (Schema::getIndexes($table) as $index) {
        if (($index['columns'][0] ?? null) === $column) {
            return true;
        }
    }

    return false;
}

test('every table a list screen sorts by has an index on its owner column', function (string $table) {
    // Every list is scoped by owner before anything else, so this is the first
    // column every one of those queries touches.
    expect(hasIndexOn($table, 'owner_id'))->toBeTrue("{$table}.owner_id is not indexed");
})->with(['contacts', 'accounts', 'deals', 'activities', 'tickets']);

test("leads' own visibility-scoping columns are indexed", function () {
    // leads has no owner_id of its own any more — visibleTo() scopes it
    // through lead_assignees instead, so that is where the equivalent
    // leading columns need to live.
    expect(hasIndexOn('lead_assignees', 'user_id'))->toBeTrue('lead_assignees.user_id is not indexed')
        ->and(hasIndexOn('lead_assignees', 'tenant_id'))->toBeTrue('lead_assignees.tenant_id is not indexed')
        ->and(hasIndexOn('lead_assignees', 'lead_id'))->toBeTrue('lead_assignees.lead_id is not indexed');
});

test('the columns the sweeps and schedules scan are indexed', function (string $table, string $column) {
    expect(hasIndexOn($table, $column))->toBeTrue("{$table}.{$column} is not indexed");
})->with([
    // Every one of these is the leading column of a query that runs on a timer
    // against the whole table.
    'ticket SLA response' => ['tickets', 'first_response_due_at'],
    'ticket SLA resolution' => ['tickets', 'resolution_due_at'],
    'scheduled reports' => ['report_schedules', 'is_active'],
    // The reminder sweep leads with status, not with due_at — the index
    // that matters is the one whose first column the query filters on.
    'activity reminders' => ['activities', 'status'],
    'notification log' => ['notification_logs', 'status'],
]);

test('every foreign key column is indexed', function () {
    $missing = [];

    foreach (Schema::getTables() as $table) {
        $name = $table['name'];

        if (str_starts_with($name, 'telescope_') || $name === 'migrations') {
            continue;
        }

        foreach (Schema::getForeignKeys($name) as $foreign) {
            $column = $foreign['columns'][0] ?? null;

            if ($column !== null && ! hasIndexOn($name, $column)) {
                $missing[] = $name.'.'.$column;
            }
        }
    }

    // MySQL creates one with the constraint, so this is really a guard against
    // somebody dropping an index and leaving the constraint behind.
    expect($missing)->toBe([], 'Foreign keys with no index: '.implode(', ', $missing));
});

// -- Caching -------------------------------------------------------------------

test('the settings a request reads on every page are cached', function () {
    app(SettingsManager::class)->flush();

    $cold = queryCount(fn () => app(SettingsManager::class)->get('company', 'company.name'));
    $warm = queryCount(fn () => app(SettingsManager::class)->get('company', 'company.name'));

    // Read on every page render, so a query each would be a query on every
    // page for a value that changes once a year.
    expect($warm)->toBeLessThan(max(1, $cold));
});

test('the notification matrix is cached', function () {
    app(NotificationMatrix::class)->flush();
    $matrix = app(NotificationMatrix::class);

    $cold = queryCount(fn () => $matrix->isEnabled('user.joined', RecipientType::Admin, NotificationChannel::InApp));
    $warm = queryCount(fn () => $matrix->isEnabled('user.joined', RecipientType::Admin, NotificationChannel::InApp));

    expect($warm)->toBe(0)->and($cold)->toBeGreaterThanOrEqual(0);
});

// -- Queue tuning --------------------------------------------------------------

test('the production supervisor retries and does not run forever', function () {
    $production = config('horizon.environments.production.supervisor-1');

    // A job that fails once and is never retried is a notification nobody gets
    // and an import nobody notices stopping.
    expect($production['tries'])->toBeGreaterThan(1)
        // A timeout below the queue's retry_after, or a job is retried while
        // the first copy is still running.
        ->and($production['timeout'])->toBeLessThan(config('queue.connections.redis.retry_after'))
        ->and($production['maxProcesses'])->toBeGreaterThan(1);
});

test('a long job has a queue of its own', function () {
    // Read from defaults, which is where the queue list is configured —
    // Horizon merges defaults into each environment at runtime, and config()
    // does not.
    $queues = config('horizon.defaults.supervisor-1.queue');

    // Imports, exports and PDFs take seconds; a notification takes
    // milliseconds. On one queue the quick ones wait behind the slow ones.
    expect($queues)->toContain('default')
        ->and($queues)->toContain('heavy');
});
