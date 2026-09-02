<?php

namespace App\Domain\Teams\Actions;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SyncTeamMembersAction
{
    /**
     * Replace a team's membership, keeping each user's active team consistent.
     *
     * @param  array<int, int>  $memberIds
     */
    public function __invoke(Team $team, array $memberIds): Team
    {
        return DB::transaction(function () use ($team, $memberIds) {
            $memberIds = array_values(array_unique(array_map('intval', $memberIds)));

            $previousIds = $team->users()->pluck('users.id')->all();

            $team->users()->sync($memberIds);

            $removedIds = array_diff($previousIds, $memberIds);
            $addedIds = array_diff($memberIds, $previousIds);

            // Someone dropped from the team must not keep it as their active
            // team, or "team" access level would still show them its records.
            if ($removedIds !== []) {
                User::query()
                    ->whereIn('id', $removedIds)
                    ->where('current_team_id', $team->id)
                    ->update(['current_team_id' => null]);
            }

            // A new member with nowhere else to sit gets this team as active, so
            // team-scoped visibility resolves to something.
            if ($addedIds !== []) {
                User::query()
                    ->whereIn('id', $addedIds)
                    ->whereNull('current_team_id')
                    ->update(['current_team_id' => $team->id]);
            }

            return $team->refresh();
        });
    }
}
