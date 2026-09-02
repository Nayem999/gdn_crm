<?php

namespace App\Domain\Users\Actions;

use App\Domain\Users\Models\UserInvitation;
use App\Domain\Users\Notifications\UserInvitationNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class InviteUserAction
{
    /**
     * Create (or refresh) an invitation and queue the email carrying its link.
     */
    public function __invoke(
        string $email,
        ?string $name = null,
        ?int $roleId = null,
        ?int $teamId = null,
        ?User $invitedBy = null,
    ): UserInvitation {
        $plainToken = Str::random(64);

        $invitation = DB::transaction(function () use ($email, $name, $roleId, $teamId, $invitedBy, $plainToken) {
            // Re-inviting the same address replaces the outstanding invitation
            // rather than piling up rows, and invalidates the previous link.
            return UserInvitation::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'token' => UserInvitation::hashToken($plainToken),
                    'role_id' => $roleId,
                    'team_id' => $teamId,
                    'invited_by' => $invitedBy?->id,
                    'expires_at' => now()->addDays(config('auth.invitations.expire_days', 7)),
                    'accepted_at' => null,
                ]
            );
        });

        Notification::route('mail', $email)
            ->notify(new UserInvitationNotification($invitation, $plainToken));

        return $invitation;
    }
}
