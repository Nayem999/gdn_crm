<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadAssignee;
use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\Recipient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Moves a lead's escalation ladder along.
 *
 * A sweep, not a job scheduled per lead, for the same reason activity
 * reminders are one: a delayed job would have to be found and cancelled every
 * time an assignee changed, a priority moved, or the lead was converted, and a
 * queue with no such job looks exactly like one that lost it. Reading the
 * table each run cannot drift from it.
 *
 * **This is a timer, not an activity detector.** There is no signal here for
 * "somebody looked at this lead" — only for "this tier has been the current
 * one for this long." A tier that is genuinely being worked and one that is
 * simply quiet look identical to this sweep, and escalating the quiet one
 * alongside the worked one is the tradeoff a pure timer makes.
 */
class EscalateLeadAssignmentsAction
{
    public function __construct(private readonly Notifier $notifier) {}

    /**
     * @return int how many leads escalated to their next priority tier
     */
    public function __invoke(?Carbon $now = null): int
    {
        $hours = (int) settings('leads.escalation_hours', 24);

        if ($hours <= 0) {
            return 0;
        }

        $now ??= now();
        $escalated = 0;

        Lead::query()
            ->open()
            ->whereHas('assignees', fn ($query) => $query->whereNotNull('priority'))
            ->with('assignees.user')
            ->chunkById(200, function (Collection $leads) use (&$escalated, $hours, $now) {
                foreach ($leads as $lead) {
                    $escalated += $this->escalateOne($lead, $hours, $now);
                }
            });

        return $escalated;
    }

    /**
     * @return int 1 if this lead escalated, 0 otherwise
     */
    private function escalateOne(Lead $lead, int $hours, Carbon $now): int
    {
        /** @var Collection<int, Collection<int, LeadAssignee>> $tiers */
        $tiers = $lead->assignees
            ->filter(fn (LeadAssignee $assignee) => $assignee->priority !== null)
            ->groupBy('priority')
            ->sortKeys();

        // A single tier — everybody prioritised sits at the same rank, or
        // only one person is on the ladder at all — has nowhere to escalate
        // to, whatever "stale" would mean for it.
        if ($tiers->count() < 2) {
            return 0;
        }

        $priorities = $tiers->keys()->all();
        $previousEscalatedAt = null;

        foreach ($priorities as $index => $priority) {
            $tier = $tiers->get($priority);

            if ($tier->every(fn (LeadAssignee $row) => $row->escalated_at !== null)) {
                // This tier's turn already ended; its own escalation moment
                // is the floor for whichever tier turns out to be current.
                $previousEscalatedAt = $tier->max('escalated_at');

                continue;
            }

            // The first tier not yet escalated past is the one currently
            // being given a chance to work the lead.
            $nextPriority = $priorities[$index + 1] ?? null;

            if ($nextPriority === null) {
                // The last rung. Nobody is left to hand this to.
                return 0;
            }

            $reference = $tier->min('assigned_at');

            if ($previousEscalatedAt !== null && $previousEscalatedAt->greaterThan($reference)) {
                // Assigned long ago but only just became the current tier —
                // the clock for THEM starts when their turn began, not when
                // they were first put on the ladder.
                $reference = $previousEscalatedAt;
            }

            if ($reference->diffInHours($now, absolute: true) < $hours && $reference->lessThanOrEqualTo($now)) {
                return 0;
            }

            foreach ($tier as $row) {
                $row->forceFill(['escalated_at' => $now])->save();
            }

            $this->notify($lead, $tiers->get($nextPriority), $hours);

            return 1;
        }

        // Every tier has already had its turn end, with nobody further to go.
        return 0;
    }

    /**
     * @param  Collection<int, LeadAssignee>  $tier
     */
    private function notify(Lead $lead, Collection $tier, int $hours): void
    {
        foreach ($tier as $assignee) {
            $this->notifier->send(
                'leads.assignment_escalated',
                [Recipient::user($assignee->user, RecipientType::AssignedAgent)],
                ['lead' => ['name' => $lead->fullName(), 'hours' => $hours]],
                null,
                route('leads.show', $lead->id),
            );
        }
    }
}
