<?php

namespace App\Domain\Users\Actions;

use App\Domain\Notifications\Notifier;
use App\Domain\Users\Models\UserInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;

class AcceptInvitationAction
{
    /**
     * Turn a pending invitation into a real user account.
     *
     * @throws RuntimeException when the invitation is no longer usable
     */
    public function __invoke(UserInvitation $invitation, string $name, string $password): User
    {
        if ($invitation->isAccepted()) {
            throw new RuntimeException('This invitation has already been accepted.');
        }

        if ($invitation->isExpired()) {
            throw new RuntimeException('This invitation has expired.');
        }

        $user = DB::transaction(function () use ($invitation, $name, $password) {
            $user = new User([
                'name' => $name,
                'email' => $invitation->email,
                'password' => Hash::make($password),
                'current_team_id' => $invitation->team_id,
            ]);

            // Into the workspace of whoever invited them. Accepting happens
            // signed out, so there is no workspace being acted for to take.
            $user->setAttribute('tenant_id', $invitation->invitedBy?->tenant_id);
            $user->save();

            // Following the emailed link proves they control the address, so
            // there's nothing left to verify. Set outside the mass-assignment
            // above deliberately: verification state is not fillable.
            $user->forceFill(['email_verified_at' => now()])->save();

            if ($invitation->role_id !== null) {
                $role = Role::query()->find($invitation->role_id);

                if ($role !== null) {
                    $user->syncRoles([$role]);
                }
            }

            if ($invitation->team_id !== null) {
                $user->teams()->syncWithoutDetaching([$invitation->team_id]);
            }

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });

        // Outside the transaction: queueing work inside one can fire before the
        // rows it refers to are committed.
        app(Notifier::class)->sendToAdmins('user.joined', 'users.view', [
            'user' => ['name' => $user->name, 'email' => $user->email],
        ], $user);

        return $user;
    }
}
