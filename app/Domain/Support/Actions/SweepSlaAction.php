<?php

namespace App\Domain\Support\Actions;

use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\Ticket;
use App\Domain\Support\SlaClock;
use App\Domain\Support\TicketNotifications;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Finds the tickets that have reached a warning or a breach, and acts on them.
 *
 * A sweep rather than a job per ticket, for the reason the activity reminders
 * are: a delayed job would have to be found and cancelled every time somebody
 * put a ticket on hold, retriaged it or answered it, and a queue missing that
 * job looks identical to one that lost it. Reading the table each minute cannot
 * drift from it.
 *
 * Each stamp is written once, so the ticket itself is the record of what has
 * already been said — a sweep running every minute must not send the same
 * warning fourteen hundred times.
 */
class SweepSlaAction
{
    public function __construct(
        private readonly SlaClock $clock,
        private readonly TicketNotifications $notifications,
        private readonly EscalateTicketAction $escalate,
    ) {}

    /**
     * @return array{warned: int, breached: int}
     */
    public function __invoke(?Carbon $now = null): array
    {
        $now ??= now();
        $warned = 0;
        $breached = 0;

        $this->candidates()->chunkById(200, function ($tickets) use (&$warned, &$breached, $now) {
            foreach ($tickets as $ticket) {
                foreach ([SlaClock::RESPONSE, SlaClock::RESOLUTION] as $kind) {
                    if ($this->breach($ticket, $kind, $now)) {
                        $breached++;

                        // Breaching subsumes the warning: a ticket that went
                        // straight past both while the scheduler was down does
                        // not need telling it was nearly late.
                        continue;
                    }

                    if ($this->warn($ticket, $kind, $now)) {
                        $warned++;
                    }
                }
            }
        });

        return ['warned' => $warned, 'breached' => $breached];
    }

    /**
     * Open tickets with a clock running and something still unstamped.
     *
     * @return Builder<Ticket>
     */
    private function candidates(): Builder
    {
        return Ticket::query()
            ->whereIn('status', TicketStatus::openValues())
            // On hold is an open status, but its clock is stopped — excluded
            // here so a held ticket is not even read, rather than read and then
            // found to be paused.
            ->whereNull('sla_paused_at')
            ->whereNotNull('sla_policy_id')
            ->where(function ($query) {
                $query
                    ->whereNotNull('first_response_due_at')
                    ->orWhereNotNull('resolution_due_at');
            })
            ->with(['owner', 'contact', 'account', 'watchers', 'slaPolicy.targets']);
    }

    private function breach(Ticket $ticket, string $kind, Carbon $now): bool
    {
        $column = $kind === SlaClock::RESPONSE ? 'response_breached_at' : 'resolution_breached_at';

        if ($ticket->getAttribute($column) !== null || ! $this->clock->hasBreached($ticket, $kind, $now)) {
            return false;
        }

        $ticket->forceFill([$column => $now])->save();

        // Escalate first, so the message names the priority the ticket now has
        // rather than the one it had when it ran out of time.
        $this->escalate->__invoke($ticket, $now);

        $this->notifications->slaBreached($ticket, $kind);

        return true;
    }

    private function warn(Ticket $ticket, string $kind, Carbon $now): bool
    {
        $column = $kind === SlaClock::RESPONSE ? 'response_warned_at' : 'resolution_warned_at';

        if ($ticket->getAttribute($column) !== null || ! $this->clock->isWarning($ticket, $kind, $now)) {
            return false;
        }

        $ticket->forceFill([$column => $now])->save();

        $this->notifications->slaWarning($ticket, $kind);

        return true;
    }
}
