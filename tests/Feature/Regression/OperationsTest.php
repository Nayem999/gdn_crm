<?php

use App\Console\Commands\BackupDatabase;
use App\Listeners\VerifyApplicationHealth;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The things 11.4 asks for that can be asserted without a deploy: the pipeline
 * runs the gate, the backup writes somewhere nothing serves, the health
 * endpoint means what a monitor assumes, and a logged exception says who it
 * happened to.
 */

/**
 * The CI workflow, as text.
 *
 * Read rather than parsed: symfony/yaml is not installed, and buying a
 * dependency to assert on a config file is a poor trade. Everything below is a
 * question about a line being present, which text answers exactly as well.
 */
function opsWorkflow(): string
{
    $path = base_path('.github/workflows/ci.yml');

    expect(file_exists($path))->toBeTrue('There is no CI workflow.');

    return (string) file_get_contents($path);
}

// -- The pipeline --------------------------------------------------------------

test('the pipeline runs on a push and on a pull request', function () {
    expect(opsWorkflow())->toContain('push:')
        ->toContain('pull_request:');
});

test('the pipeline runs the whole gate', function (string $step) {
    // The same three the working agreement requires locally. A pipeline that
    // ran only the tests would let a formatting or a types failure through.
    expect(opsWorkflow())->toContain($step);
})->with([
    'pint' => ['pint --test'],
    'phpstan' => ['phpstan analyse'],
    'tests' => ['artisan test'],
]);

test('the pipeline enforces the coverage the brief sets', function () {
    // --min fails the build below the threshold rather than printing a number
    // nobody reads. It is enforced here because the development machine has no
    // coverage driver.
    expect(opsWorkflow())->toContain('--min=80');
});

test('the pipeline runs against the services the application actually uses', function () {
    // Not SQLite: the suite relies on real foreign keys and on MySQL's own
    // behaviour around ONLY_FULL_GROUP_BY and unsigned arithmetic, both of
    // which SQLite allows silently.
    expect(opsWorkflow())->toContain('mysql:8')
        ->toContain('redis:7');
});

test('the pipeline audits its dependencies', function () {
    expect(opsWorkflow())->toContain('composer audit');
});

// -- Backups -------------------------------------------------------------------

test('the backup command is registered and scheduled', function () {
    expect(array_keys(app(Kernel::class)->all()))
        ->toContain('backup:database');

    $scheduled = collect(app(Schedule::class)->events())
        ->map(fn ($event) => (string) $event->command)
        ->implode(' ');

    expect($scheduled)->toContain('backup:database');
});

test('backups are written where nothing serves them', function () {
    // The whole database in a file the web server hands out is a worse hole
    // than having no backup at all.
    expect(BackupDatabase::DIRECTORY)->toBe('backups');

    expect((string) config('filesystems.disks.local.root'))->toContain('private')
        ->and(config('filesystems.disks.local.visibility'))->not->toBe('public');
});

/**
 * @return array<int, string>
 */
function backupArguments(): array
{
    $command = new ReflectionMethod(BackupDatabase::class, 'command');

    return $command->invoke(app(BackupDatabase::class), (string) config('database.default'), 'testing', '/tmp/x.sql');
}

test('the dump command never puts the password through a shell', function () {
    config()->set('database.connections.'.config('database.default').'.dump_binary', null);

    $arguments = backupArguments();

    // An argument list, not a string: a database name with a quote in it
    // cannot become part of the command.
    expect($arguments)->toBeArray()
        ->and($arguments[0])->toBe('mysqldump')
        ->and(implode(' ', $arguments))->toContain('--single-transaction');
});

test('the dump binary can be pointed at one outside the PATH', function () {
    config()->set('database.connections.'.config('database.default').'.dump_binary', '/opt/lampp/bin/mysqldump');

    expect(backupArguments()[0])->toBe('/opt/lampp/bin/mysqldump');
});

test('routines and events are only dumped when the server will list them', function (string $flag, string $probe) {
    // A MariaDB whose system tables predate it refuses these listings outright,
    // which used to fail the whole backup on an un-upgraded XAMPP install.
    try {
        DB::select($probe);
        $listable = true;
    } catch (QueryException) {
        $listable = false;
    }

    expect(in_array($flag, backupArguments(), true))->toBe($listable);
})->with([
    'routines' => ['--routines', 'show function status where Db = database()'],
    'events' => ['--events', 'show events'],
]);

// -- Health --------------------------------------------------------------------

test('the health endpoint answers when everything is up', function () {
    $this->get('/up')->assertOk();
});

test('the health check refuses when a dependency is unreachable', function () {
    $listener = new VerifyApplicationHealth;

    $check = new ReflectionMethod($listener, 'check');
    $check->setAccessible(true);

    // The mechanism itself: a probe that throws becomes a failed health check,
    // which becomes a 500 on /up. Laravel's own endpoint answers 200 in this
    // situation because the framework booted, which is the thing worth
    // changing — a monitor reads that as "fine" while every page is a 500.
    expect(fn () => $check->invoke($listener, 'database', function (): void {
        throw new RuntimeException('gone away');
    }))->toThrow(RuntimeException::class, 'Health check failed on the database');
});

test('the health check covers the three things a request cannot do without', function () {
    $source = (string) file_get_contents(
        (string) (new ReflectionClass(VerifyApplicationHealth::class))->getFileName()
    );

    // Database, cache and disk. The cache carries the settings and the
    // permission matrix; the private disk carries documents and backups.
    expect($source)->toContain("check('database'")
        ->toContain("check('cache'")
        ->toContain("check('storage'");
});

// -- Error context -------------------------------------------------------------

test('a logged exception says who it happened to and where', function () {
    $user = User::factory()->create();

    $context = [];

    Log::listen(function ($message) use (&$context) {
        $context = $message->context;
    });

    $this->actingAs($user)->get('/reports');

    report(new RuntimeException('something went wrong'));

    // An id rather than a name or an address: a log is the wrong place for
    // personal data, and an id is enough to find somebody.
    expect($context)->toBeArray()
        ->and($context)->toHaveKey('user_id')
        ->and($context['user_id'])->toBe($user->id)
        ->and($context)->toHaveKey('url')
        ->and($context)->toHaveKey('ip');
});
