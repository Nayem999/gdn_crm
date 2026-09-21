<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Settings\SettingsNavigation;
use App\Domain\Shared\UI\NavIconPalette;
use App\Models\User;
use Illuminate\Support\Facades\File;

/**
 * Navigation icons carry their own colour, and the two navigations agree about
 * which colour that is.
 */
function colourfulUser(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $model) {
        $user->givePermissionTo($model);
    }

    return $user->fresh();
}

test('the sidebar draws each module icon in its own colour', function () {
    $response = $this->actingAs(colourfulUser(['leads.view', 'contacts.view', 'accounts.view', 'deals.view']))
        ->get('/');

    $response->assertSuccessful()
        ->assertSee('text-sky-400', escape: false)      // Dashboard
        ->assertSee('text-rose-400', escape: false)     // Leads
        ->assertSee('text-cyan-400', escape: false)     // Contacts
        ->assertSee('text-indigo-400', escape: false)   // Accounts
        ->assertSee('text-emerald-400', escape: false); // Deals
});

test('the sidebar no longer paints every icon the same grey', function () {
    $response = $this->actingAs(colourfulUser(['leads.view']))->get('/');

    // The old treatment: one grey for all of them, washed to white on hover.
    // Asserted as absent because "colourful" is only true if nothing is still
    // falling through to the previous default.
    $response->assertDontSee('h-5 w-5 shrink-0 text-slate-400', escape: false);
});

test('the Settings and Guide rows are coloured too', function () {
    $response = $this->actingAs(colourfulUser(['settings.view', 'company.view']))->get('/');

    $response->assertSuccessful()
        ->assertSee('text-purple-400', escape: false)  // the Settings gear
        ->assertSee('text-pink-400', escape: false);   // the Guide question mark
});

test('the navigation under Settings is coloured on both themes', function () {
    $user = colourfulUser(['company.view', 'users.view', 'roles.view']);

    $response = $this->actingAs($user)->get(route('settings.company'));

    // A pair, not a single shade: this navigation sits on the page background,
    // which is light in one theme and dark in the other.
    $response->assertSuccessful()
        ->assertSee('text-indigo-600 dark:text-indigo-400', escape: false)  // Company
        ->assertSee('text-sky-600 dark:text-sky-400', escape: false)        // Users
        ->assertSee('text-emerald-600 dark:text-emerald-400', escape: false); // Roles
});

test('an icon means the same colour in both navigations', function () {
    // building-2 is Accounts in the sidebar and Company under Settings. One map
    // answers both, so the two cannot drift apart.
    expect(NavIconPalette::onDark('building-2'))->toBe('text-indigo-400')
        ->and(NavIconPalette::onPage('building-2'))->toBe('text-indigo-600 dark:text-indigo-400');
});

test('every icon in either navigation gets a colour', function () {
    $icons = [];

    foreach (SettingsNavigation::sections() as $section) {
        foreach ($section['items'] as $item) {
            $icons[] = $item['icon'];
        }
    }

    // The sidebar's icons, read out of the template rather than restated here:
    // a list copied into a test is a list that stops matching.
    preg_match_all(
        "/'icon' => '([a-z0-9-]+)'/",
        (string) File::get(resource_path('views/layouts/partials/sidebar.blade.php')),
        $matches
    );

    $icons = array_unique([...$icons, ...$matches[1], 'settings', 'circle-help']);

    expect($icons)->not->toBeEmpty();

    foreach ($icons as $icon) {
        expect(NavIconPalette::onDark($icon))->toStartWith('text-')
            ->and(NavIconPalette::onDark($icon))->not->toContain('slate');
    }
});

test('an icon nobody listed still gets a stable colour', function () {
    // A module an administrator creates at runtime picks its own icon, and the
    // next settings group somebody adds will too. Neither should come out grey,
    // and neither should change colour between requests — a landmark that moves
    // is not a landmark.
    $first = NavIconPalette::onDark('rocket');

    expect($first)->toStartWith('text-')
        ->and($first)->not->toContain('slate')
        ->and(NavIconPalette::onDark('rocket'))->toBe($first);
});
