<?php

namespace App\Domain\Workflows\Policies;

use App\Domain\Workflows\Models\Workflow;
use App\Models\User;

/**
 * A workflow changes records automatically, on behalf of everybody, so
 * configuring one is an administrative act rather than a per-record one: there
 * is no access level here, only the permission.
 *
 * Reading the execution log is separate from editing definitions. Somebody
 * answering "why did this lead get reassigned last night" needs the log and
 * nothing else, and that is a much wider audience than the people who should be
 * able to change what happens tonight.
 */
class WorkflowPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('workflows.view');
    }

    public function view(User $user, Workflow $workflow): bool
    {
        return $user->can('workflows.view');
    }

    public function create(User $user): bool
    {
        return $user->can('workflows.create');
    }

    public function update(User $user, Workflow $workflow): bool
    {
        return $user->can('workflows.update');
    }

    public function delete(User $user, Workflow $workflow): bool
    {
        return $user->can('workflows.delete');
    }

    /**
     * Switching a workflow on is what makes it start changing records, so it
     * follows the update permission rather than the view one.
     */
    public function toggle(User $user, Workflow $workflow): bool
    {
        return $user->can('workflows.update');
    }

    public function viewLog(User $user): bool
    {
        return $user->can('workflows.logs');
    }

    /**
     * Re-running a failed step writes to records, so it is not something a
     * reader of the log can do.
     */
    public function retry(User $user): bool
    {
        return $user->can('workflows.update');
    }
}
