<?php

namespace App\Domain\Teams\Actions;

use App\Domain\Teams\DTOs\TeamData;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UpdateTeamAction
{
    public function __construct(private SyncTeamMembersAction $syncMembers) {}

    /**
     * @throws RuntimeException when the parent would create a cycle
     */
    public function __invoke(Team $team, TeamData $data): Team
    {
        $this->guardAgainstCycle($team, $data->parentId);

        return DB::transaction(function () use ($team, $data) {
            $team->update($data->toAttributes());

            ($this->syncMembers)($team, $data->memberIds);

            return $team->refresh();
        });
    }

    /**
     * A team cannot be parented to itself or to one of its own descendants —
     * that detaches the branch from the tree and loops any traversal.
     */
    private function guardAgainstCycle(Team $team, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($parentId === $team->id) {
            throw new RuntimeException('A team cannot be its own parent.');
        }

        $candidate = Team::query()->find($parentId);

        if ($candidate !== null && $candidate->isDescendantOf($team)) {
            throw new RuntimeException('A team cannot be moved beneath one of its own sub-teams.');
        }
    }
}
