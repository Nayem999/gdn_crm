<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Leads\Models\Lead;
use App\Domain\Tenancy\Tenancy;
use App\Models\User;

/**
 * Every other test runs with a workspace already set (tests/Pest.php), which
 * is exactly what hid this: a real request starts with none, and the
 * middleware has to set it before anything reads tenant-scoped data —
 * route model binding included. These forget the workspace first, the way a
 * fresh request starts.
 */
function tenantResolutionUser(): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models(['leads.view', 'leads.update']) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

test('a lead page resolves its workspace before looking the lead up', function (string $route) {
    $user = tenantResolutionUser();
    $lead = Lead::factory()->ownedBy($user)->create();

    app(Tenancy::class)->forget();

    // SubstituteBindings used to run first, find no workspace, match nothing
    // through the tenant scope, and answer 404 for every lead.
    $this->actingAs($user)->get(route($route, $lead))->assertOk();
})->with([
    'detail' => ['leads.show'],
    'edit' => ['leads.edit'],
]);

test('the API resolves the workspace of the key\'s owner', function () {
    $user = tenantResolutionUser();
    Lead::factory()->ownedBy($user)->count(2)->create();
    $key = $user->createToken('resolution', ['read'])->plainTextToken;

    app(Tenancy::class)->forget();

    // The api group never set a workspace, so every scoped list came back
    // empty.
    $this->withHeader('Authorization', 'Bearer '.$key)
        ->getJson('/api/v1/leads')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
