<?php

use App\Domain\Access\Actions\SyncPermissionCatalogueAction;
use App\Domain\Access\PermissionCatalogue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Bringing an upgraded installation's permissions up to date.
 *
 * The failure this guards against does not look like a failure: the catalogue
 * is a constant in the code and the rows are in the database, so an
 * installation upgraded rather than freshly installed lacks every permission a
 * new phase added — and the only symptom is a sidebar section that renders
 * correctly with nothing in it, because a sidebar hides what its viewer may not
 * see. Phase 12 shipped exactly that way.
 */
beforeEach(function () {
    // A freshly migrated database has no permissions at all, so each test
    // starts from the state an installed application is actually in.
    app(SyncPermissionCatalogueAction::class)->execute();
});

test('a permission added since the last install reaches the protected role', function () {
    $role = Role::query()->where('name', PermissionCatalogue::SUPER_ADMIN_ROLE)->firstOrFail();

    // An installation that was seeded before the module existed.
    $newest = Permission::query()->where('name', 'meta.campaigns.view')->firstOrFail();
    $role->revokePermissionTo($newest);
    $newest->delete();

    expect(Role::query()->where('name', PermissionCatalogue::SUPER_ADMIN_ROLE)->firstOrFail()
        ->hasPermissionTo('meta.view'))->toBeTrue();

    $this->artisan('permissions:sync')
        ->expectsOutputToContain('newly written')
        ->assertSuccessful();

    expect(Permission::query()->where('name', 'meta.campaigns.view')->exists())->toBeTrue()
        ->and($role->fresh()->hasPermissionTo('meta.campaigns.view'))->toBeTrue();
});

test('running it twice adds nothing the second time', function () {
    $this->artisan('permissions:sync')->assertSuccessful();

    $this->artisan('permissions:sync')
        ->expectsOutputToContain('nothing new to add')
        ->assertSuccessful();

    expect(Permission::query()->count())->toBe(count(PermissionCatalogue::all()));
});

test('it leaves every other role exactly as it was', function () {
    $role = Role::query()->create(['name' => 'Marketing assistant', 'guard_name' => 'web']);
    $role->givePermissionTo('leads.view');

    $this->artisan('permissions:sync')->assertSuccessful();

    // A deployment step must never widen somebody's deliberate choice.
    expect($role->fresh()->permissions()->pluck('name')->all())->toBe(['leads.view']);
});
