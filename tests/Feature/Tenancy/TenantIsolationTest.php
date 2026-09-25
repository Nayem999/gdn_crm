<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Leads\Models\Lead;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Tenancy;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| One customer cannot see another
|--------------------------------------------------------------------------
|
| The shared-database bet: isolation is a property of the application rather
| than of the schema. These are the tests that make that bet checkable, so
| they are written against the ways data actually escapes — a list, a count, a
| relationship load, a direct find by id — rather than against the trait.
|
*/

/**
 * A second workspace with somebody in it.
 *
 * @return array{0: Tenant, 1: User}
 */
function tenancyOther(array $permissions = ['leads.view']): array
{
    $tenancy = app(Tenancy::class);

    $tenant = $tenancy->withoutScope(fn (): Tenant => Tenant::factory()->create(['name' => 'Other Business']));

    $user = $tenancy->for($tenant, function () use ($permissions): User {
        $user = User::factory()->create();

        foreach (PermissionResolver::models($permissions) as $model) {
            $user->givePermissionTo($model);
        }

        return $user->fresh();
    });

    return [$tenant, $user];
}

test('a lead is stamped with the workspace that created it', function () {
    $lead = Lead::factory()->create();

    // Nothing passed a tenant. The point of the trait is that no call site
    // has to: a create in a controller, a job or a webhook lands in the
    // workspace the process is acting for, or it fails loudly.
    expect($lead->tenant_id)->toBe(app(Tenancy::class)->id());
});

test('a list shows only this workspace', function () {
    Lead::factory()->count(2)->create();

    [$other] = tenancyOther();
    app(Tenancy::class)->for($other, fn () => Lead::factory()->count(3)->create());

    expect(Lead::query()->count())->toBe(2)
        ->and(Lead::query()->get()->pluck('tenant_id')->unique()->all())
        ->toBe([app(Tenancy::class)->id()]);
});

test('another workspace lead cannot be found by its id', function () {
    [$other] = tenancyOther();
    $theirs = app(Tenancy::class)->for($other, fn (): Lead => Lead::factory()->create());

    // The way a leak usually happens: an id in a URL. The scope is on the
    // query builder, so a find by primary key is scoped like everything else
    // and the authorisation check never even gets a record.
    expect(Lead::query()->find($theirs->id))->toBeNull()
        ->and(Lead::query()->whereKey($theirs->id)->exists())->toBeFalse();
});

test('a request with no workspace sees nothing, rather than everything', function () {
    Lead::factory()->count(3)->create();

    app(Tenancy::class)->forget();

    // The decision that sets what a mistake costs. An unidentified request
    // returning an empty screen is reported in minutes; one returning every
    // customer's data is reported by the customer.
    expect(Lead::query()->count())->toBe(0);
});

test('writing into another workspace is refused', function () {
    [$other] = tenancyOther();

    // Not a hypothetical: it is what a job carrying the wrong id, or a form
    // with a tenant_id in it, would do.
    expect(fn () => Lead::factory()->create(['tenant_id' => $other->id]))
        ->toThrow(RuntimeException::class, 'Refusing to write');
});

test('deliberate cross-tenant work has to name the workspace it writes to', function () {
    $tenancy = app(Tenancy::class);

    // withoutScope is for work that is about every workspace — provisioning,
    // an admin tool. It does not get a default, because the default would be
    // whichever workspace happened to be set last.
    expect(fn () => $tenancy->withoutScope(fn () => Lead::factory()->create()))
        ->toThrow(RuntimeException::class, 'switched off');
});

test('reading across workspaces is possible, and has to be asked for', function () {
    Lead::factory()->count(2)->create();

    [$other] = tenancyOther();
    app(Tenancy::class)->for($other, fn () => Lead::factory()->count(3)->create());

    $all = app(Tenancy::class)->withoutScope(fn (): int => Lead::query()->count());

    expect($all)->toBe(5);
});

test('the workspace in force is restored after acting as another', function () {
    $before = app(Tenancy::class)->id();

    [$other] = tenancyOther();

    try {
        app(Tenancy::class)->for($other, function (): void {
            throw new RuntimeException('something failed mid-job');
        });
    } catch (RuntimeException) {
        // Deliberately swallowed: the point is what the failure left behind.
    }

    // A command looping workspaces must not strand the next one inside the
    // customer whose run blew up.
    expect(app(Tenancy::class)->id())->toBe($before);
});

test('an authenticated request acts for the workspace of the user signed in', function () {
    [$other, $theirUser] = tenancyOther();

    // Owned by them, because the list also applies record-level visibility
    // and this test is about the workspace boundary rather than that one.
    $theirLead = app(Tenancy::class)->for(
        $other,
        fn (): Lead => Lead::factory()->ownedBy($theirUser)->create(['last_name' => 'Okonkwo']),
    );

    $ourLead = Lead::factory()->create(['last_name' => 'Fairweather']);

    // Through the middleware, the way a real request arrives.
    $response = $this->actingAs($theirUser)->get(route('leads.index'));

    $response->assertSuccessful()
        ->assertSee($theirLead->last_name)
        ->assertDontSee($ourLead->last_name);
});

test('a suspended workspace is not served', function () {
    [$other, $theirUser] = tenancyOther();
    app(Tenancy::class)->withoutScope(fn () => $other->update(['is_active' => false]));

    // The data stays theirs and stays here. They are simply not served until
    // somebody turns it back on.
    $this->actingAs($theirUser)->get(route('leads.index'))->assertForbidden();
});
