<?php

use App\Domain\Access\PermissionResolver;
use App\Models\User;
use Illuminate\Support\Str;

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

/**
 * wire:navigate copies the incoming document's <html> attributes over the live
 * ones, and the server never renders the dark class. Without this listener the
 * theme was dropped on every SPA navigation and the toggle looked broken.
 */
test('the chosen theme is re-applied after a wire:navigate page swap', function () {
    $response = $this->get('/');

    $response->assertSuccessful();
    $response->assertSee("addEventListener('livewire:navigated', applyStoredTheme)", escape: false);
    $response->assertSee("classList.toggle('dark', stored === 'dark'", escape: false);
});

test('every module in the sidebar is a real link, hidden by permission rather than shown inert', function () {
    // This test used to assert the opposite: that modules whose phase had not
    // landed appeared as inert placeholders. Automation left that list in 5.5,
    // Products in 6.1, Quotes in 6.3, Support in 9.1 and Reports in 10.2 —
    // which was the last of them. What is worth guarding now is the rule that
    // replaced it: an entry somebody cannot use is absent, never a dead link.
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger)->get('/');

    $response->assertSuccessful();

    // No permissions at all, so no module rows — and no inert ones either.
    $response->assertDontSee('aria-disabled="true"', false)
        ->assertDontSee('Coming in a later phase');

    foreach (['Leads', 'Deals', 'Reports', 'Support'] as $module) {
        $response->assertDontSee('>'.$module.'</a>', false);
    }
});

/**
 * Deals and Activities used to be asserted alongside the placeholders above,
 * and the assertion passed without ever reading the sidebar: the old dashboard
 * body printed both words in its own stub cards, so `assertSee` matched the
 * page rather than the navigation. Task 3.7 replaced that body and the test
 * failed — correctly, because a person holding no permission should not be
 * offered a Deals link.
 *
 * So they are asserted here instead, against a user who may actually see them,
 * and against one who may not.
 */
test('a module that has landed is a link for somebody who may see it, and absent for somebody who may not', function () {
    $user = User::factory()->create();

    foreach (PermissionResolver::models(['deals.view', 'activities.view']) as $model) {
        $user->givePermissionTo($model);
    }

    $this->actingAs($user->fresh())
        ->get('/')
        ->assertSuccessful()
        ->assertSee(route('deals.index'), false)
        ->assertSee(route('activities.index'), false)
        ->assertSee(route('calendar'), false);

    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertSuccessful()
        ->assertDontSee(route('deals.index'), false)
        ->assertDontSee(route('activities.index'), false);
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
    // Asserted on the link's URL rather than the label: the dashboard has its
    // own "Leads" and "Deals" tiles, so a label alone proves nothing about the
    // sidebar.
    $this->get('/')->assertSuccessful()->assertDontSee(route($route), false);

    $this->actingAs(settingsAwareUser($permission));

    $this->get('/')
        ->assertSuccessful()
        ->assertSee($label)
        ->assertSee(route($route), false);
})->with([
    'accounts' => ['Accounts', 'accounts.view', 'accounts.index'],
    'contacts' => ['Contacts', 'contacts.view', 'contacts.index'],
    'leads' => ['Leads', 'leads.view', 'leads.index'],
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

test('the sidebar links to the guide for everyone, whatever they may do', function () {
    // Ungated on purpose: the page is public, and the one person who most needs
    // the manual is the one who has just been given an account and no
    // permissions yet.
    $this->get('/')
        ->assertSuccessful()
        ->assertSee('Guide')
        ->assertSee(route('guide'), false);

    $this->actingAs(settingsAwareUser('settings.view'));

    $this->get('/')->assertSuccessful()->assertSee(route('guide'), false);
});

test('the guide link opens in a new tab rather than swapping the app shell', function () {
    // /guide is a standalone page outside this layout: wire:navigate would swap
    // the whole shell out for it, and the reader would lose the screen they
    // were asking about.
    $html = $this->get('/')->assertSuccessful()->getContent();

    $link = Str::of($html)->after(route('guide'))->before('</a>')->value();

    expect($link)->toContain('target="_blank"')
        ->toContain('rel="noopener"')
        ->not->toContain('wire:navigate');
});

test('the layout loads the compiled tailwind stylesheet and the livewire/alpine script bundle', function () {
    $response = $this->get('/');

    $response->assertSuccessful();
    $response->assertSee('/build/assets/app-', escape: false);
    $response->assertSee('/livewire/livewire.js', escape: false);
});

test('the marketing section gathers the channels and the advertising', function () {
    // Task 12: answering a customer on WhatsApp and reading what an
    // advertisement cost are the same person's morning, and the Meta screens
    // used to be reachable only by going through Settings.
    $marketer = User::factory()->create();

    foreach (PermissionResolver::models(['social.inbox.view', 'meta.campaigns.view', 'meta.view']) as $permission) {
        $marketer->givePermissionTo($permission);
    }

    $this->actingAs($marketer->fresh())
        ->get('/')
        ->assertSuccessful()
        ->assertSee('Marketing &amp; social', false)
        ->assertSee('Chat inbox')
        ->assertSee('Meta ads')
        ->assertSee(route('settings.meta.campaigns'), false);
});

test('a heading with nothing under it is not shown at all', function () {
    // A header standing over no rows tells somebody a feature exists and then
    // refuses to say where.
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get('/')
        ->assertSuccessful()
        ->assertDontSee('Marketing &amp; social', false)
        ->assertDontSee('Insight');
});

test('a Meta row in the sidebar does not light up on every settings page', function () {
    // Every one of those routes begins "settings", and the highlight used to
    // match on everything before the first dot.
    $marketer = User::factory()->create();

    foreach (PermissionResolver::models(['meta.campaigns.view', 'settings.view', 'users.view']) as $permission) {
        $marketer->givePermissionTo($permission);
    }

    $html = $this->actingAs($marketer->fresh())
        ->get(route('settings.users'))
        ->assertSuccessful()
        ->getContent();

    expect(substr_count($html, 'aria-current="page"'))->toBeLessThanOrEqual(1);
});
