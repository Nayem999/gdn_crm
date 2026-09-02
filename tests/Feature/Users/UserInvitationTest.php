<?php

use App\Domain\Users\Models\UserInvitation;
use App\Domain\Users\Notifications\UserInvitationNotification;
use App\Livewire\Users\AcceptInvitation;
use App\Livewire\Users\InviteUser;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function inviter(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::findOrCreate('users.invite'));

    return $user;
}

test('inviting a user is only possible with the users.invite permission', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(InviteUser::class)->assertForbidden();
});

test('an invitation is recorded and the email is queued', function () {
    Notification::fake();

    $this->actingAs($inviter = inviter());

    $role = Role::create(['name' => 'Sales Rep']);
    $team = Team::factory()->create();

    Livewire::test(InviteUser::class)
        ->set('email', 'newcomer@example.com')
        ->set('name', 'New Comer')
        ->set('roleId', (string) $role->id)
        ->set('teamId', (string) $team->id)
        ->call('invite')
        ->assertHasNoErrors()
        ->assertRedirect(route('settings.users'));

    $invitation = UserInvitation::query()->sole();

    expect($invitation->email)->toBe('newcomer@example.com')
        ->and($invitation->name)->toBe('New Comer')
        ->and($invitation->role_id)->toBe($role->id)
        ->and($invitation->team_id)->toBe($team->id)
        ->and($invitation->invited_by)->toBe($inviter->id)
        ->and($invitation->accepted_at)->toBeNull()
        ->and($invitation->expires_at->isFuture())->toBeTrue();

    Notification::assertSentOnDemand(
        UserInvitationNotification::class,
        fn (UserInvitationNotification $notification, array $channels, object $notifiable) => $notifiable->routes['mail'] === 'newcomer@example.com'
    );
});

test('the invitation notification is queued rather than sent inline', function () {
    expect(is_subclass_of(UserInvitationNotification::class, ShouldQueue::class))->toBeTrue();
});

test('the invitation token is only ever stored as a hash', function () {
    Notification::fake();

    $this->actingAs(inviter());

    Livewire::test(InviteUser::class)
        ->set('email', 'newcomer@example.com')
        ->call('invite');

    $invitation = UserInvitation::query()->sole();
    $plainToken = null;

    Notification::assertSentOnDemand(
        UserInvitationNotification::class,
        function (UserInvitationNotification $notification) use (&$plainToken) {
            $plainToken = $notification->plainToken;

            return true;
        }
    );

    expect($plainToken)->not->toBeNull()
        ->and($invitation->getAttribute('token'))->not->toBe($plainToken)
        ->and($invitation->getAttribute('token'))->toBe(UserInvitation::hashToken($plainToken))
        // The hash must never be serialised out with the model either.
        ->and($invitation->toArray())->not->toHaveKey('token');
});

test('inviting an address that already has an account is rejected', function () {
    Notification::fake();

    $this->actingAs(inviter());

    User::factory()->create(['email' => 'existing@example.com']);

    Livewire::test(InviteUser::class)
        ->set('email', 'existing@example.com')
        ->call('invite')
        ->assertHasErrors(['email' => 'unique']);

    Notification::assertNothingSent();
});

test('re-inviting the same address replaces the outstanding invitation', function () {
    Notification::fake();

    $this->actingAs(inviter());

    UserInvitation::factory()->withToken('the-original-token')->create(['email' => 'newcomer@example.com']);

    Livewire::test(InviteUser::class)
        ->set('email', 'newcomer@example.com')
        ->call('invite')
        ->assertHasNoErrors();

    expect(UserInvitation::query()->where('email', 'newcomer@example.com')->count())->toBe(1)
        // The superseded link no longer resolves.
        ->and(UserInvitation::findPendingByToken('the-original-token'))->toBeNull();
});

test('the acceptance screen loads for a valid token', function () {
    $token = Str::random(64);
    UserInvitation::factory()->withToken($token)->create([
        'email' => 'newcomer@example.com',
        'name' => 'New Comer',
    ]);

    $this->get(route('invitations.accept', ['token' => $token]))
        ->assertSuccessful()
        ->assertSee('Accept your invitation')
        ->assertSee('newcomer@example.com');
});

test('an unknown, expired or already accepted token is indistinguishably not found', function (string $state) {
    $token = Str::random(64);

    if ($state !== 'unknown') {
        UserInvitation::factory()->withToken($token)->{$state}()->create();
    }

    $this->get(route('invitations.accept', ['token' => $token]))->assertNotFound();
})->with(['unknown', 'expired', 'accepted']);

test('accepting an invitation creates the account with its role and team', function () {
    $token = Str::random(64);
    $role = Role::create(['name' => 'Sales Rep']);
    $team = Team::factory()->create();

    UserInvitation::factory()->withToken($token)->create([
        'email' => 'newcomer@example.com',
        'name' => 'New Comer',
        'role_id' => $role->id,
        'team_id' => $team->id,
    ]);

    Livewire::test(AcceptInvitation::class, ['token' => $token])
        ->assertSet('email', 'newcomer@example.com')
        ->assertSet('name', 'New Comer')
        ->set('password', 'correct-horse-battery')
        ->set('password_confirmation', 'correct-horse-battery')
        ->call('accept')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'newcomer@example.com')->sole();

    expect($user->name)->toBe('New Comer')
        ->and(Hash::check('correct-horse-battery', $user->password))->toBeTrue()
        ->and($user->hasRole('Sales Rep'))->toBeTrue()
        ->and($user->current_team_id)->toBe($team->id)
        ->and($user->teams->pluck('id')->all())->toBe([$team->id])
        ->and($user->email_verified_at)->not->toBeNull();

    $this->assertAuthenticatedAs($user);

    expect(UserInvitation::query()->sole()->accepted_at)->not->toBeNull();
});

test('accepting an invitation requires a name and a confirmed password', function () {
    $token = Str::random(64);
    UserInvitation::factory()->withToken($token)->create(['name' => null]);

    Livewire::test(AcceptInvitation::class, ['token' => $token])
        ->set('name', '')
        ->set('password', 'correct-horse-battery')
        ->set('password_confirmation', 'mismatch')
        ->call('accept')
        ->assertHasErrors(['name' => 'required', 'password' => 'confirmed']);

    $this->assertGuest();
});

test('an invitation cannot be accepted twice', function () {
    $token = Str::random(64);
    $invitation = UserInvitation::factory()->withToken($token)->create(['email' => 'newcomer@example.com']);

    $component = Livewire::test(AcceptInvitation::class, ['token' => $token])
        ->set('name', 'New Comer')
        ->set('password', 'correct-horse-battery')
        ->set('password_confirmation', 'correct-horse-battery');

    // Simulate the link being used elsewhere between load and submit.
    $invitation->forceFill(['accepted_at' => now()])->save();

    $component->call('accept')->assertHasErrors('token');

    expect(User::query()->where('email', 'newcomer@example.com')->exists())->toBeFalse();
});

test('an authenticated user is redirected away from the acceptance screen', function () {
    $token = Str::random(64);
    UserInvitation::factory()->withToken($token)->create();

    $this->actingAs(User::factory()->create())
        ->get(route('invitations.accept', ['token' => $token]))
        ->assertRedirect(route('dashboard'));
});
