<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Leads\Models\Lead;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;

function errorPageUser(): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models(['leads.view']) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

test('a refused action shows the 403 page with the reason', function () {
    $user = errorPageUser();
    $somebodyElses = Lead::factory()->create();

    $this->actingAs($user)
        ->get(route('leads.show', $somebodyElses))
        ->assertForbidden()
        ->assertSee('403')
        ->assertSee('This action is unauthorized.')
        ->assertSee('Go to the dashboard');
});

test('a 403 raised with its own reason shows that reason', function () {
    // A suspended workspace says why, rather than the generic line.
    $user = errorPageUser();
    Tenant::query()->whereKey($user->tenant_id)->update(['is_active' => false]);

    $this->actingAs($user)
        ->get(route('leads.index'))
        ->assertForbidden()
        ->assertSee('This workspace is suspended.');
});

test('a missing record shows the 404 page without naming the model', function () {
    $this->actingAs(errorPageUser())
        ->get(route('leads.show', 999999))
        ->assertNotFound()
        ->assertSee('Not Found.')
        ->assertSee('Go to the dashboard')
        // Laravel's own message reads "No query results for model [App\...]".
        ->assertDontSee('App\\Domain', false)
        ->assertDontSee('No query results');
});

test('an address that matches no route shows the same page, signed out', function () {
    // No route means no web middleware and no session: nothing on the page may
    // assume a signed-in user.
    $this->get('/there-is-no-such-page')
        ->assertNotFound()
        ->assertSee('Not Found.')
        ->assertSee(route('dashboard'), false);
});
