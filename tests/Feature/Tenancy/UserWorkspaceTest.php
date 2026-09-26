<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Leads\Models\Lead;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Tenancy;
use App\Domain\Users\Actions\AcceptInvitationAction;
use App\Domain\Users\Models\UserInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Nothing that created a user used to set tenant_id, so everybody added after
 * tenancy arrived was served no workspace and saw an empty leads list — even
 * leads assigned to them.
 */
test('a user created while acting for a workspace joins it', function () {
    $tenant = app(Tenancy::class)->current();

    $user = User::query()->create(['name' => 'Emran', 'email' => 'emran@example.com', 'password' => 'secret-password']);

    expect($user->tenant_id)->toBe($tenant->id);
});

test('a user created with no workspace in hand joins the default one', function () {
    // Sign-up and the installer run signed out.
    $default = Tenant::query()->orderBy('id')->firstOrFail();
    app(Tenancy::class)->forget();

    $user = User::query()->create(['name' => 'Sign Up', 'email' => 'signup@example.com', 'password' => 'secret-password']);

    expect($user->tenant_id)->toBe($default->id);
});

test('an accepted invitation joins the inviter\'s workspace', function () {
    $other = Tenant::factory()->create(['slug' => 'second-workspace']);
    $inviter = User::factory()->create();
    $inviter->forceFill(['tenant_id' => $other->id])->save();
    $invitation = UserInvitation::factory()->create(['invited_by' => $inviter->id]);

    // Accepting happens signed out; the default workspace is not theirs.
    app(Tenancy::class)->forget();

    $user = app(AcceptInvitationAction::class)($invitation, 'Invited Person', 'secret-password-1');

    expect($user->tenant_id)->toBe($other->id);
});

test('a user assigned a lead sees it once they have a workspace', function () {
    $user = User::factory()->create();
    $user->givePermissionTo(PermissionResolver::models(['leads.view']));
    $lead = Lead::factory()->ownedBy($user)->named('Assigned', 'Lead')->create();

    app(Tenancy::class)->forget();

    $this->actingAs($user)->get(route('leads.index'))->assertOk()->assertSee('Assigned Lead');
    $this->actingAs($user)->get(route('leads.show', $lead))->assertOk();
});

test('the backfill gives workspaceless users the only workspace there is', function () {
    $migration = require database_path('migrations/2026_09_26_091755_assign_workspace_to_users_without_one.php');
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['tenant_id' => null]);

    $migration->up();

    expect($user->fresh()->tenant_id)->toBe(Tenant::query()->orderBy('id')->value('id'));
});
