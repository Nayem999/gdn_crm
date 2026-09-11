<?php

namespace App\Domain\Dashboard;

use App\Domain\Activities\ActivityRelations;
use App\Domain\Audit\AuditLogger;
use App\Domain\Timeline\TimelineEntry;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity as AuditEntry;

/**
 * What has been happening across the CRM lately.
 *
 * The audit trail, not the Activities module — so the rows are the same entries
 * the record timeline's history strand shows, and they are presented through
 * `TimelineEntry::fromActivity()` so the two screens cannot disagree about one.
 *
 * **This is not the audit viewer.** That screen is gated by `audit.view` and is
 * deliberately unscoped, because an administrator reading the trail needs to
 * see everything. A dashboard is not that: it is a person's own view of their
 * own work, so every row here is filtered to a record they could open.
 */
class DashboardFeed
{
    public const DEFAULT_LIMIT = 12;

    /**
     * @return array<int, TimelineEntry>
     */
    public function for(DashboardScope $scope, int $limit = self::DEFAULT_LIMIT): array
    {
        $modules = $scope->modules();

        if ($modules === []) {
            return [];
        }

        $entries = AuditEntry::query()
            ->with('causer')
            ->where('log_name', AuditLogger::LOG_NAME)
            ->where(function (Builder $outer) use ($scope, $modules) {
                foreach ($modules as $module) {
                    $visible = $scope->query($module);
                    $morphClass = ActivityRelations::morphClass($module);

                    if ($visible === null || $morphClass === null) {
                        continue;
                    }

                    // A subquery per module rather than a list of ids: the id
                    // list is unbounded, and materialising every account
                    // somebody can see in order to read twelve feed rows is the
                    // unbounded read the architecture rules forbid.
                    //
                    // The subject is matched on type *and* id together. A
                    // polymorphic table addressed by id alone would show a
                    // contact's entries against an account sharing its number.
                    $outer->orWhere(fn (Builder $inner) => $inner
                        ->where('subject_type', $morphClass)
                        ->whereIn('subject_id', $visible->reorder()->select($visible->qualifyColumn('id')))
                    );
                }
            })
            // id is the tiebreaker, not decoration: entries written in the same
            // second come back in whatever order the engine chose otherwise,
            // and the feed would reshuffle between renders. Same rule as the
            // audit viewer and the record timeline.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $entries->map(fn (AuditEntry $entry): TimelineEntry => TimelineEntry::fromActivity($entry))->all();
    }
}
