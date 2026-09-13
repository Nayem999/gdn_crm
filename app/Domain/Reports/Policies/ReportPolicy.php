<?php

namespace App\Domain\Reports\Policies;

use App\Domain\Reports\Models\Report;
use App\Models\User;

/**
 * Who may do what to a saved question.
 *
 * Note what is *not* here: whether the report may be run against a module. That
 * is `Report::runnableBy()`, asked at run time, because a shared report about
 * deals should be listed for everybody and refuse to produce numbers for
 * somebody with no deals.view. A report that appeared for one colleague and not
 * another would look like a fault rather than a permission.
 */
class ReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('reports.view');
    }

    public function view(User $user, Report $report): bool
    {
        return $user->can('reports.view')
            && ($report->is_shared || $report->owner_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->can('reports.create');
    }

    /**
     * Your own, or anybody's once it is shared and you may edit reports.
     *
     * A standard report is editable in the same way — it is meant to be a
     * starting point — but it cannot be removed, which is the delete rule
     * below.
     */
    public function update(User $user, Report $report): bool
    {
        return $user->can('reports.update') && $this->view($user, $report);
    }

    public function delete(User $user, Report $report): bool
    {
        if ($report->is_standard) {
            // Half the application links to these.
            return false;
        }

        return $user->can('reports.delete') && $this->view($user, $report);
    }

    /**
     * Making a report visible to everybody is its own decision, and its own
     * permission: somebody's working draft becoming company-wide is not the
     * same act as editing it.
     */
    public function share(User $user, Report $report): bool
    {
        return $user->can('reports.share') && $this->view($user, $report);
    }

    public function schedule(User $user, Report $report): bool
    {
        return $user->can('reports.schedule') && $this->view($user, $report);
    }
}
