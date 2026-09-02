<?php

namespace App\Domain\Teams\Actions;

use App\Models\Team;

class DeleteTeamAction
{
    /**
     * Remove a team. The schema handles the fallout: sub-teams are promoted to
     * the root (parent_id nulls), memberships cascade away, and members whose
     * active team this was fall back to seeing only their own records.
     */
    public function __invoke(Team $team): void
    {
        $team->delete();
    }
}
