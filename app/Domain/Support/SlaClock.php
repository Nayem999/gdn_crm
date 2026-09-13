<?php

namespace App\Domain\Support;

use App\Domain\Support\Models\Ticket;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * How long is left, and whether we have already run out.
 *
 * The clock is stored as two due times on the ticket rather than computed from
 * a policy each time it is read. That is what makes "which tickets are about to
 * breach" a range scan instead of a join nobody can index — and it means the
 * promise a ticket was given does not silently change when somebody edits the
 * policy afterwards.
 *
 * Pausing shifts the due times forward rather than being subtracted at read
 * time. A due time that moves while a ticket is on hold is the honest one to
 * show a customer: we said four hours of *our* time, and the hold was not ours.
 * The alternative — a fixed due time and a running total of pauses — displays a
 * deadline that has already passed on a ticket that has not breached.
 */
class SlaClock
{
    public const RESPONSE = 'response';

    public const RESOLUTION = 'resolution';

    /**
     * Whether the clock is stopped because the ticket is on hold.
     */
    public function isPaused(Ticket $ticket): bool
    {
        return $ticket->sla_paused_at !== null;
    }

    /**
     * Whether a first response is still owed.
     *
     * Answered and settled tickets both stop owing one: nobody is waiting on a
     * first reply to a ticket that has been resolved.
     */
    public function owesResponse(Ticket $ticket): bool
    {
        return $ticket->first_response_due_at !== null
            && $ticket->first_responded_at === null
            && ! $ticket->status()->isSettled();
    }

    public function owesResolution(Ticket $ticket): bool
    {
        return $ticket->resolution_due_at !== null
            && $ticket->resolved_at === null
            && ! $ticket->status()->isSettled();
    }

    /**
     * The due time for one side of the promise.
     */
    public function dueAt(Ticket $ticket, string $kind): ?CarbonInterface
    {
        return $kind === self::RESPONSE
            ? $ticket->first_response_due_at
            : $ticket->resolution_due_at;
    }

    /**
     * Minutes left, negative once the deadline has gone.
     *
     * Null when nothing is owed — "no promise" and "no time left" are different
     * answers and a screen must not print the second for the first.
     */
    public function minutesRemaining(Ticket $ticket, string $kind, ?Carbon $now = null): ?float
    {
        $due = $this->dueAt($ticket, $kind);

        if ($due === null) {
            return null;
        }

        $owed = $kind === self::RESPONSE ? $this->owesResponse($ticket) : $this->owesResolution($ticket);

        if (! $owed) {
            return null;
        }

        // A paused clock has whatever it had when the hold began. Measuring to
        // now would count the hold against us, which is the whole point of
        // pausing.
        $at = $this->isPaused($ticket) ? $ticket->sla_paused_at : ($now ?? now());

        return round($at->diffInMinutes($due, false), 2);
    }

    /**
     * Whether the deadline has gone by without the promise being met.
     *
     * Never true while paused: a ticket sitting on hold is not breaching.
     */
    public function hasBreached(Ticket $ticket, string $kind, ?Carbon $now = null): bool
    {
        $remaining = $this->minutesRemaining($ticket, $kind, $now);

        return $remaining !== null && ! $this->isPaused($ticket) && $remaining < 0;
    }

    /**
     * Whether the warning point has been reached and the deadline has not.
     */
    public function isWarning(Ticket $ticket, string $kind, ?Carbon $now = null): bool
    {
        if ($this->isPaused($ticket) || $this->hasBreached($ticket, $kind, $now)) {
            return false;
        }

        $at = $this->warnAt($ticket, $kind);

        return $at !== null && ($now ?? now())->greaterThanOrEqualTo($at);
    }

    /**
     * When the warning for one side of the promise is due.
     *
     * Worked back from the due time and the target's own length, so a policy
     * that warns at 80% warns four-fifths of the way through whatever it
     * promised — not at a fixed number of minutes that would be most of a
     * four-hour target and nothing at all on a five-day one.
     */
    public function warnAt(Ticket $ticket, string $kind): ?CarbonInterface
    {
        $due = $this->dueAt($ticket, $kind);
        $policy = $ticket->slaPolicy;

        if ($due === null || $policy === null) {
            return null;
        }

        $minutes = $this->targetMinutes($ticket, $kind);

        if ($minutes === null || $minutes <= 0) {
            return null;
        }

        return $due->copy()->subMinutes((int) round($minutes * (1 - $policy->warnAtFraction())));
    }

    /**
     * The promise in minutes, as the policy states it today.
     *
     * Only ever used to place the warning inside a window whose end is already
     * fixed — the due time itself is never recomputed from this, so editing a
     * policy cannot move a deadline a ticket was already given.
     */
    public function targetMinutes(Ticket $ticket, string $kind): ?int
    {
        $target = $ticket->slaPolicy?->targetFor($ticket->priority());

        if ($target === null) {
            return null;
        }

        return $kind === self::RESPONSE
            ? $target->first_response_minutes
            : $target->resolution_minutes;
    }

    /**
     * A short phrase for a screen: how long is left, or how long ago it went.
     */
    public function label(Ticket $ticket, string $kind, ?Carbon $now = null): ?string
    {
        $remaining = $this->minutesRemaining($ticket, $kind, $now);

        if ($remaining === null) {
            return null;
        }

        $phrase = $this->duration(abs($remaining));

        if ($this->isPaused($ticket)) {
            return $phrase.' left, on hold';
        }

        return $remaining < 0 ? $phrase.' overdue' : $phrase.' left';
    }

    private function duration(float $minutes): string
    {
        if ($minutes < 60) {
            return round($minutes).' min';
        }

        if ($minutes < 60 * 24) {
            return round($minutes / 60, 1).' h';
        }

        return round($minutes / (60 * 24), 1).' d';
    }
}
