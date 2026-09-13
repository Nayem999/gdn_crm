<?php

namespace App\Domain\Support\Policies;

use App\Domain\Support\Models\SlaPolicy;
use App\Models\User;

/**
 * Configuring what the company promises is an administrative act, not a support
 * one — so it has its own permission rather than riding on tickets.update.
 * An agent working a queue does not get to change the deadline they are being
 * measured against.
 *
 * There is no access-level question here: a policy is company-wide
 * configuration, not somebody's record.
 */
class SlaPolicyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('tickets.sla');
    }

    public function view(User $user, SlaPolicy $policy): bool
    {
        return $user->can('tickets.sla');
    }

    public function create(User $user): bool
    {
        return $user->can('tickets.sla');
    }

    public function update(User $user, SlaPolicy $policy): bool
    {
        return $user->can('tickets.sla');
    }

    public function delete(User $user, SlaPolicy $policy): bool
    {
        return $user->can('tickets.sla');
    }
}
