<?php

namespace App\Domain\Teams\Actions;

use App\Domain\Teams\DTOs\TeamData;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

class CreateTeamAction
{
    public function __construct(private SyncTeamMembersAction $syncMembers) {}

    public function __invoke(TeamData $data): Team
    {
        return DB::transaction(function () use ($data) {
            $team = Team::create($data->toAttributes());

            ($this->syncMembers)($team, $data->memberIds);

            return $team;
        });
    }
}
