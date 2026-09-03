<?php

use App\Domain\Access\PermissionResolver;
use App\Models\User;

// The dashboard sits behind `auth` as of task 1.2, so the shell is only
// reachable as a signed-in user.
beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function settingsAwareUser(string $permission): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models([$permission]) as $model) {
        $user->givePermissionTo($model);
    }

    return $user->fresh();
}

test('the dashboard renders the app shell with sidebar and topbar', function () {
    $response = $this->get('/');

    $response->assertSuccessful();
    $response->assertSee('Dashboard', escape: false);
    $response->assertSeeInOrder(['aria-label="Primary"', 'Search leads, contacts, deals'], escape: false);
});

test('the app shell exposes a working dark mode toggle', function () {
    $response = $this->get('/');

    $response->assertSuccessful();
    $response->assertSee('Toggle dark mode', escape: false);
    $response->assertSee("classList.toggle('dark')", escape: false);
    $response->assertSee("localStorage.setItem('theme'", escape: false);
    $response->assertSee('prefersDark', escape: false);
});

test('the sidebar lists every core module with an icon', function () {
    $response = $this->get('/');

    $response->assertSuccessful();

    // Modules whose phase has not landed are inert placeholders, not links.
    foreach (['Leads', 'Deals', 'Activities', 'Products', 'Quotes & Invoices', 'Support', 'Reports', 'Automation'] as $module) {
        $response->assertSee($module);
    }

    $response->assertSee('aria-disabled="true"', false);
});

/**
 * A module with a real screen is offered only to someone who may open it — the
 * same rule the Settings link follows. Add a row here as each module lands.
 */
test('a module with a route is only offered to someone who may open it', function (
    string $label,
    string $permission,
    string $route,
) {
    $this->get('/')->assertSuccessful()->assertDontSee($label);

    $this->actingAs(settingsAwareUser($permission));

    $this->get('/')
        ->assertSuccessful()
        ->assertSee($label)
        ->assertSee(route($route), false);
})->with([
    'accounts' => ['Accounts', 'accounts.view', 'accounts.index'],
    'contacts' => ['Contacts', 'contacts.view', 'contacts.index'],
]);

test('the sidebar offers Settings only to someone who can open something there', function () {
    // Task 1.8: the link used to point at Company for everyone, which meant a
    // guaranteed 403 for anyone without company.view. It now lands on the first
    // settings page the viewer can actually reach, and is hidden when there is
    // none.
    $this->get('/')->assertSuccessful()->assertDontSee('Settings');

    $this->actingAs(settingsAwareUser('settings.view'));

    $this->get('/')
        ->assertSuccessful()
        ->assertSee('Settings')
        ->assertSee(route('settings.group', 'localisation'), false);
});

test('the layout loads the compiled tailwind stylesheet and the livewire/alpine script bundle', function () {
    $response = $this->get('/');

    $response->assertSuccessful();
    $response->assertSee('/build/assets/app-', escape: false);
    $response->assertSee('/livewire/livewire.js', escape: false);
});
