<?php

namespace App\Domain\Support\Analytics;

use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketSource;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the support desk actually did.
 *
 * Two measurement decisions run through everything here, and both are the
 * difference between a figure somebody can act on and one that flatters:
 *
 * **Resolution time subtracts holds; first-response time does not.** A ticket
 * held waiting for a supplier was not ours to resolve during the hold, which is
 * exactly what the SLA clock says — an analytics screen that disagreed with the
 * clock would have somebody arguing about which number was real. But a hold
 * *before we have answered at all* is itself a failure to answer, so the
 * first-response figure is wall-clock from arrival.
 *
 * **Every average comes with a median and a count.** One ticket that sat over a
 * bank holiday drags a mean by days, and a desk reading only the mean concludes
 * something untrue about the other two hundred.
 */
class SupportMetrics
{
    /**
     * CAST to SIGNED before subtracting the pause.
     *
     * `sla_paused_seconds` is an UNSIGNED column, and MySQL promotes the whole
     * expression to BIGINT UNSIGNED — so a ticket whose recorded hold is longer
     * than its wall-clock life does not clamp to zero, it overflows and takes
     * the entire query down with error 1690. GREATEST alone does not save it,
     * because the subtraction happens first.
     */
    public const PAUSE_SECONDS = 'CAST(tickets.sla_paused_seconds AS SIGNED)';

    /**
     * How many resolved tickets one window will pull durations for.
     *
     * A median has to be computed in PHP — no portable SQL for it across the
     * two engines — so the set is bounded. Beyond this the median is reported
     * from the most recent slice rather than silently loading a year of rows.
     */
    public const DURATION_SAMPLE = 5000;

    /**
     * How many tickets came in, were resolved, and are still open.
     *
     * @return array{raised: int, resolved: int, open_now: int, reopened: int, breached: int}
     */
    public function volume(SupportPeriod $period, User $viewer): array
    {
        $raised = $this->scope($viewer)
            ->whereBetween('tickets.created_at', [$period->from, $period->to])
            ->count();

        // Counted by when they were resolved, not when they were raised: "how
        // much did we get through this month" is a different question from "how
        // much came in", and a ticket can answer both or neither.
        $resolved = $this->scope($viewer)
            ->whereBetween('tickets.resolved_at', [$period->from, $period->to])
            ->count();

        $openNow = $this->scope($viewer)
            ->whereIn('tickets.status', TicketStatus::openValues())
            ->count();

        // Resolved at some point and open again now — the clearest sign that
        // something was closed before it was fixed.
        $reopened = $this->scope($viewer)
            ->whereIn('tickets.status', TicketStatus::openValues())
            ->whereNotNull('tickets.resolution_breached_at')
            ->count();

        $breached = $this->scope($viewer)
            ->whereBetween('tickets.created_at', [$period->from, $period->to])
            ->where(function (Builder $query) {
                $query->whereNotNull('tickets.resolution_breached_at')
                    ->orWhereNotNull('tickets.response_breached_at');
            })
            ->count();

        return [
            'raised' => $raised,
            'resolved' => $resolved,
            'open_now' => $openNow,
            'reopened' => $reopened,
            'breached' => $breached,
        ];
    }

    /**
     * Tickets raised per day, with every day in the window present.
     *
     * The empty days matter: a chart that omitted them would draw a quiet
     * fortnight as a straight line between two busy ones.
     *
     * @return array<string, int>
     */
    public function volumeByDay(SupportPeriod $period, User $viewer): array
    {
        $rows = $this->scope($viewer)
            ->whereBetween('tickets.created_at', [$period->from, $period->to])
            ->selectRaw('DATE(tickets.created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $days = [];
        $cursor = $period->from->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($period->to)) {
            $key = $cursor->format('Y-m-d');
            $days[$key] = (int) ($rows[$key] ?? 0);
            $cursor->addDay();
        }

        return $days;
    }

    /**
     * How long it took to fix things, in hours, net of holds.
     *
     * @return array{count: int, average: float|null, median: float|null, within_sla: float|null}
     */
    public function resolutionTime(SupportPeriod $period, User $viewer): array
    {
        $rows = $this->scope($viewer)
            ->whereBetween('tickets.resolved_at', [$period->from, $period->to])
            ->whereNotNull('tickets.resolved_at')
            ->selectRaw(
                'GREATEST(TIMESTAMPDIFF(SECOND, tickets.created_at, tickets.resolved_at) - '.self::PAUSE_SECONDS.', 0) as seconds'
            )
            ->orderByDesc('tickets.resolved_at')
            ->limit(self::DURATION_SAMPLE)
            // pluck, not get(): the alias is not a column on the model, and
            // reading it as a property would be a property that does not exist.
            ->pluck('seconds');

        $hours = $rows->map(fn ($seconds) => round((int) $seconds / 3600, 2))->all();

        // Only tickets that were given a promise count towards attainment. A
        // desk running without a policy has not met 100% of nothing.
        $promised = $this->scope($viewer)
            ->whereBetween('tickets.resolved_at', [$period->from, $period->to])
            ->whereNotNull('tickets.resolution_due_at')
            ->count();

        $missed = $this->scope($viewer)
            ->whereBetween('tickets.resolved_at', [$period->from, $period->to])
            ->whereNotNull('tickets.resolution_due_at')
            ->whereNotNull('tickets.resolution_breached_at')
            ->count();

        return [
            'count' => count($hours),
            'average' => $this->average($hours),
            'median' => $this->median($hours),
            'within_sla' => $promised === 0 ? null : round(($promised - $missed) / $promised * 100, 1),
        ];
    }

    /**
     * How long customers waited for a first reply, in minutes, wall-clock.
     *
     * @return array{count: int, average: float|null, median: float|null, unanswered: int}
     */
    public function firstResponseTime(SupportPeriod $period, User $viewer): array
    {
        $rows = $this->scope($viewer)
            ->whereBetween('tickets.created_at', [$period->from, $period->to])
            ->whereNotNull('tickets.first_responded_at')
            ->selectRaw('TIMESTAMPDIFF(SECOND, tickets.created_at, tickets.first_responded_at) as seconds')
            ->orderByDesc('tickets.created_at')
            ->limit(self::DURATION_SAMPLE)
            ->pluck('seconds');

        $minutes = $rows->map(fn ($seconds) => round((int) $seconds / 60, 1))->all();

        // Reported beside the average rather than folded into it: a desk that
        // answered ten tickets in a minute each and ignored forty has a
        // wonderful average and a problem.
        $unanswered = $this->scope($viewer)
            ->whereBetween('tickets.created_at', [$period->from, $period->to])
            ->whereNull('tickets.first_responded_at')
            ->whereIn('tickets.status', TicketStatus::openValues())
            ->count();

        return [
            'count' => count($minutes),
            'average' => $this->average($minutes),
            'median' => $this->median($minutes),
            'unanswered' => $unanswered,
        ];
    }

    /**
     * What each agent got through.
     *
     * @return array<int, AgentPerformance>
     */
    public function agentPerformance(SupportPeriod $period, User $viewer): array
    {
        $resolved = $this->scope($viewer)
            ->whereBetween('tickets.resolved_at', [$period->from, $period->to])
            ->selectRaw('tickets.owner_id')
            ->selectRaw('COUNT(*) as resolved_count')
            ->selectRaw(
                'AVG(GREATEST(TIMESTAMPDIFF(SECOND, tickets.created_at, tickets.resolved_at) - '.self::PAUSE_SECONDS.', 0)) as avg_seconds'
            )
            ->selectRaw('SUM(tickets.resolution_breached_at is not null) as breached_count')
            ->groupBy('tickets.owner_id')
            // Through the base query builder, so the aggregate aliases come
            // back as plain rows rather than as properties on a Ticket that has
            // no such columns. applyScopes() first, or the soft-delete scope is
            // dropped and removed tickets come back into the figures — see
            // .ai/rules/models.md.
            ->applyScopes()
            ->getQuery()
            ->get()
            ->keyBy('owner_id');

        $open = $this->scope($viewer)
            ->whereIn('tickets.status', TicketStatus::openValues())
            ->selectRaw('tickets.owner_id, COUNT(*) as open_count')
            ->groupBy('tickets.owner_id')
            ->pluck('open_count', 'owner_id');

        // Every agent who appears in either set, so somebody who resolved
        // nothing but is sitting on twenty tickets is on the list — that is the
        // row worth seeing.
        $ids = collect($resolved->keys())->merge($open->keys())->unique()->filter()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $names = User::query()->whereKey($ids->all())->pluck('name', 'id');

        $rows = [];

        foreach ($ids as $id) {
            $row = $resolved->get($id);
            $resolvedCount = (int) ($row->resolved_count ?? 0);

            $rows[] = new AgentPerformance(
                agentId: (int) $id,
                name: (string) ($names[$id] ?? 'Somebody who has since left'),
                resolved: $resolvedCount,
                stillOpen: (int) ($open[$id] ?? 0),
                averageResolutionHours: $row === null || $row->avg_seconds === null
                    ? null
                    : round((float) $row->avg_seconds / 3600, 2),
                breached: (int) ($row->breached_count ?? 0),
            );
        }

        // Most resolved first; the point of the table is who got through what.
        usort($rows, fn (AgentPerformance $a, AgentPerformance $b) => $b->resolved <=> $a->resolved);

        return $rows;
    }

    /**
     * How the window's tickets split by priority, source and status.
     *
     * @return array{priority: array<string, int>, source: array<string, int>, status: array<string, int>}
     */
    public function breakdown(SupportPeriod $period, User $viewer): array
    {
        return [
            'priority' => $this->countBy($period, $viewer, 'priority', TicketPriority::options()),
            'source' => $this->countBy($period, $viewer, 'source', TicketSource::options()),
            'status' => $this->countBy($period, $viewer, 'status', TicketStatus::options()),
        ];
    }

    /**
     * @param  array<array-key, string>  $labels
     * @return array<string, int>
     */
    private function countBy(SupportPeriod $period, User $viewer, string $column, array $labels): array
    {
        $rows = $this->scope($viewer)
            ->whereBetween('tickets.created_at', [$period->from, $period->to])
            ->selectRaw('tickets.'.$column.' as bucket, COUNT(*) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $counts = [];

        // Every bucket, including the empty ones: "nothing came in by phone" is
        // a useful thing to be told, and a list that hid it would redraw itself
        // month to month.
        foreach ($labels as $value => $label) {
            $counts[$label] = (int) ($rows[$value] ?? 0);
        }

        return $counts;
    }

    /**
     * The base query: tickets this person may see.
     *
     * Every figure goes through it, so a screen cannot report a number drawn
     * from records the viewer could not open one by one. The viewer is
     * **required** rather than nullable for that reason: a scheduled report in
     * a later phase runs with no session, and a null that quietly meant
     * "everything" is exactly how such a report leaks.
     *
     * @return Builder<Ticket>
     */
    private function scope(User $viewer): Builder
    {
        return Ticket::query()->visibleTo($viewer);
    }

    /**
     * @param  array<int, float>  $values
     */
    private function average(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 2);
    }

    /**
     * The middle value — the mean of the middle two when there is an even
     * number, so a two-ticket month does not report one of them as typical.
     *
     * @param  array<int, float>  $values
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? round($values[$middle], 2)
            : round(($values[$middle - 1] + $values[$middle]) / 2, 2);
    }
}
