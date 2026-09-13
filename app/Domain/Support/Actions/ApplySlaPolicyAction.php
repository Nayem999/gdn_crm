<?php

namespace App\Domain\Support\Actions;

use App\Domain\Support\Models\SlaPolicy;
use App\Domain\Support\Models\Ticket;
use Illuminate\Support\Carbon;

/**
 * Starts the clock on a ticket.
 *
 * Called when a ticket is raised, and again when its priority changes — a
 * ticket promoted to urgent is owed the urgent promise, measured from when it
 * came in rather than from the moment somebody noticed. Measuring from the
 * retriage would let a desk buy itself another four hours by changing a
 * dropdown.
 */
class ApplySlaPolicyAction
{
    /**
     * @param  SlaPolicy|null  $policy  The policy to apply, or null to use the default.
     * @return bool whether a clock is now running
     */
    public function __invoke(Ticket $ticket, ?SlaPolicy $policy = null, ?Carbon $now = null): bool
    {
        $policy ??= $this->defaultPolicy();

        if ($policy === null) {
            return false;
        }

        $target = $policy->loadMissing('targets')->targetFor($ticket->priority());

        if ($target === null || ! $target->promisesAnything()) {
            // A policy that promises nothing at this priority is still the
            // ticket's policy — it is what a later retriage is measured
            // against — but there is no clock to run.
            $ticket->forceFill([
                'sla_policy_id' => $policy->id,
                'first_response_due_at' => null,
                'resolution_due_at' => null,
            ])->save();

            return false;
        }

        // From when the ticket arrived, not from now.
        $from = $ticket->created_at ?? $now ?? now();

        // Holds already served are added back on, so retriage does not quietly
        // charge a desk for time the customer kept it.
        $elapsedPause = $ticket->sla_paused_seconds
            + ($ticket->sla_paused_at === null ? 0 : (int) $ticket->sla_paused_at->diffInSeconds($now ?? now()));

        $ticket->forceFill([
            'sla_policy_id' => $policy->id,
            'first_response_due_at' => $target->first_response_minutes === null
                ? null
                : $from->copy()->addMinutes($target->first_response_minutes)->addSeconds($elapsedPause),
            'resolution_due_at' => $target->resolution_minutes === null
                ? null
                : $from->copy()->addMinutes($target->resolution_minutes)->addSeconds($elapsedPause),
        ])->save();

        return true;
    }

    /**
     * The active default, if a desk has named one.
     */
    public function defaultPolicy(): ?SlaPolicy
    {
        return SlaPolicy::query()
            ->active()
            ->where('is_default', true)
            ->with('targets')
            ->first();
    }
}
