<?php

namespace App\Domain\Deals\Policies;

use App\Domain\Deals\Models\Pipeline;
use App\Models\User;

/**
 * Deciding how every deal is worked is administration, not deal work, so it has
 * its own permission rather than riding on deals.update — the same split as
 * leads.scoring.
 *
 * There is no access-level question here: a pipeline is configuration everybody
 * works inside, like a role or a status, not a record somebody owns.
 */
class PipelinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('deals.pipelines');
    }

    public function view(User $user, Pipeline $pipeline): bool
    {
        return $user->can('deals.pipelines');
    }

    public function create(User $user): bool
    {
        return $user->can('deals.pipelines');
    }

    public function update(User $user, Pipeline $pipeline): bool
    {
        return $user->can('deals.pipelines');
    }

    /**
     * The business reasons a pipeline cannot go — the default one, the last
     * one, one that still has deals — are asked here too, so a button is never
     * offered for something the action would refuse.
     */
    public function delete(User $user, Pipeline $pipeline): bool
    {
        return $user->can('deals.pipelines') && $pipeline->canBeDeleted();
    }
}
