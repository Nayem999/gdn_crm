<?php

namespace App\Domain\Reports\Policies;

use App\Domain\Reports\Models\ReportSchedule;
use App\Models\User;

/**
 * A schedule is an unattended sender of the owner's own figures, so it belongs
 * to the person who set it up and to nobody else. Not even an administrator
 * edits somebody's schedule: the attachment would still carry the owner's view
 * of the data, and changing where that goes without them knowing is the one
 * thing this must not allow.
 */
class ReportSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('reports.schedule');
    }

    public function view(User $user, ReportSchedule $schedule): bool
    {
        return $user->can('reports.schedule') && $schedule->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('reports.schedule');
    }

    public function update(User $user, ReportSchedule $schedule): bool
    {
        return $this->view($user, $schedule);
    }

    public function delete(User $user, ReportSchedule $schedule): bool
    {
        return $this->view($user, $schedule);
    }
}
