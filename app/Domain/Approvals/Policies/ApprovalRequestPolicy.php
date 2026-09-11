<?php

namespace App\Domain\Approvals\Policies;

use App\Domain\Approvals\Models\ApprovalRequest;
use App\Models\User;

/**
 * Approvals are governed by who was asked, not by a permission.
 *
 * Deliberately different from the rest of the workflow screens. Being an
 * approver is an instruction from a specific workflow to a specific person;
 * granting it through a role would mean anybody with that role could answer for
 * anybody else, which is the one thing an approval chain exists to prevent.
 *
 * `workflows.view` still opens the *list* of every approval, because somebody
 * auditing what was approved needs to read requests that were never theirs.
 */
class ApprovalRequestPolicy
{
    public function viewAny(User $user): bool
    {
        // Everybody can open their own queue; the screen scopes it to them.
        return true;
    }

    public function view(User $user, ApprovalRequest $request): bool
    {
        return $user->can('workflows.view')
            || $request->levels->contains(fn ($level): bool => $level->approver_id === $user->id);
    }

    /**
     * Only the person the chain is asking right now.
     */
    public function decide(User $user, ApprovalRequest $request): bool
    {
        return $request->status()->isOpen() && $request->isAwaiting($user->id);
    }
}
