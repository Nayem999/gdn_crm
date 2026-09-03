<?php

namespace App\Domain\Leads\Policies;

use App\Models\User;

/**
 * Configuring how every lead is scored and qualified is administration, not
 * lead work, so it has its own permission rather than riding on leads.update.
 */
class LeadScoringRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('leads.scoring');
    }

    public function update(User $user): bool
    {
        return $user->can('leads.scoring');
    }
}
