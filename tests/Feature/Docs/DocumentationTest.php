<?php

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Settings\SettingsRegistry;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Task 11.6 — the user and administrator guides.
 *
 * The brief's test for this task is "documented steps verified against the
 * running app", and that is what this does rather than checking the files
 * exist: every screen the guides send somebody to is matched against the real
 * router, every command they tell an administrator to run is matched against
 * the real console kernel, and the credentials they print are read back out of
 * the seeder that sets them.
 *
 * It is a drift test. Renaming a route or a command without touching the guides
 * fails here, which is the only way documentation stays true a year later.
 */

/**
 * @return array<int, string>
 */
function docsFiles(): array
{
    return [base_path('docs/USER_GUIDE.md'), base_path('docs/ADMIN_GUIDE.md')];
}

function docsText(): string
{
    return implode("\n", array_map(
        fn (string $path): string => (string) file_get_contents($path),
        docsFiles()
    ));
}

/**
 * Every `/path` the guides name in inline code.
 *
 * Paths carrying a {placeholder} are left out: they are illustrations of a URL
 * shape, and the concrete route they belong to is asserted through its
 * placeholder-free sibling.
 *
 * @return array<int, string>
 */
function docsPaths(): array
{
    preg_match_all('/`(\/[A-Za-z0-9._\/{}-]*)`/', docsText(), $matches);

    $paths = array_filter(
        array_unique($matches[1]),
        fn (string $path): bool => ! str_contains($path, '{')
    );

    return array_values($paths);
}

/**
 * Every `php artisan x:y` the guides tell somebody to run.
 *
 * @return array<int, string>
 */
function docsCommands(): array
{
    preg_match_all('/php artisan ([a-z0-9:-]+)/', docsText(), $matches);

    return array_values(array_unique($matches[1]));
}

test('both guides are present', function (string $file) {
    $path = base_path('docs/'.$file);

    expect(file_exists($path))->toBeTrue("Missing documentation file: {$path}")
        ->and(strlen((string) file_get_contents($path)))->toBeGreaterThan(2000);
})->with([
    'user guide' => ['USER_GUIDE.md'],
    'administrator guide' => ['ADMIN_GUIDE.md'],
]);

test('the guides cross-reference each other', function () {
    // A user who lands in the wrong guide should be one click from the right
    // one, and a broken relative link is the usual way that stops being true.
    expect(file_get_contents(base_path('docs/USER_GUIDE.md')))->toContain('ADMIN_GUIDE.md')
        ->and(file_get_contents(base_path('docs/ADMIN_GUIDE.md')))->toContain('USER_GUIDE.md');
});

test('the guides name paths worth checking at all', function () {
    // Guards the two sweeps below: a regex that quietly stopped matching would
    // otherwise turn them into tests that assert nothing.
    expect(count(docsPaths()))->toBeGreaterThan(30)
        ->and(count(docsCommands()))->toBeGreaterThan(10);
});

test('every screen the guides send you to exists', function () {
    $routes = Route::getRoutes();
    $missing = [];

    foreach (docsPaths() as $path) {
        try {
            $routes->match(Request::create($path, 'GET'));
        } catch (Throwable) {
            $missing[] = $path;
        }
    }

    expect($missing)->toBe([], 'Documented paths with no route: '.implode(', ', $missing));
});

test('every command the guides tell an administrator to run exists', function () {
    $registered = array_keys(app(Kernel::class)->all());
    $missing = array_values(array_diff(docsCommands(), $registered));

    expect($missing)->toBe([], 'Documented commands that do not exist: '.implode(', ', $missing));
});

test('the permissions the administrator guide explains are real', function (string $permission) {
    expect(PermissionCatalogue::has($permission))->toBeTrue()
        ->and(docsText())->toContain($permission);
})->with([
    'accounts.view' => ['accounts.view'],
    'deals.close' => ['deals.close'],
    'leads.convert' => ['leads.convert'],
    'settings.secrets' => ['settings.secrets'],
]);

test('the settings pages the administrator guide lists are declared groups', function () {
    // /settings/{group} is constrained to the registry, so a group that was
    // renamed stops matching and the sweep above catches it. This asserts the
    // guide covers every group rather than a subset that happens to still work.
    $documented = array_map(
        fn (string $path): string => str_replace('/settings/', '', $path),
        array_filter(docsPaths(), fn (string $path): bool => str_starts_with($path, '/settings/'))
    );

    $missing = array_diff(SettingsRegistry::groupKeys(), $documented);

    expect($missing)->toBeEmpty('Settings groups no guide mentions: '.implode(', ', $missing));
});

test('the demo credentials the administrator guide prints are the ones the seeder sets', function () {
    $guide = (string) file_get_contents(base_path('docs/ADMIN_GUIDE.md'));

    expect($guide)->toContain(DemoDataSeeder::PASSWORD)
        ->and($guide)->toContain('DemoDataSeeder');

    $seeder = (string) file_get_contents(base_path('database/seeders/DemoDataSeeder.php'));

    foreach (['priya@example.com', 'tom@example.com', 'mei@example.com'] as $email) {
        expect($guide)->toContain($email)
            ->and($seeder)->toContain($email);
    }
});

test('the scheduled work the administrator guide lists is actually scheduled', function (string $command) {
    $scheduled = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => $event->command ?? '')
        ->implode(' ');

    expect($scheduled)->toContain($command)
        ->and(docsText())->toContain('php artisan '.$command);
})->with([
    'reminders' => ['activities:send-reminders'],
    'recurrences' => ['activities:generate-recurrences'],
    'workflow triggers' => ['workflows:run-triggers'],
    'sla sweep' => ['support:sweep-sla'],
    'inbound mail' => ['mail:sync-inbound'],
    'pull sources' => ['ingest:sync'],
    'scheduled reports' => ['reports:send-scheduled'],
    'quote expiry' => ['quotes:expire'],
    'backups' => ['backup:database'],
]);
