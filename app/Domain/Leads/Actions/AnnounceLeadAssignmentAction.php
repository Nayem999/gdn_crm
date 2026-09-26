<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Models\Lead;
use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\Recipient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Tells the people newly put on a lead — assignees and the owner — that it is
 * theirs, and never the person who put them there.
 *
 * Sent after the surrounding transaction commits, so a save that rolls back
 * tells nobody about a lead that does not exist. Somebody who is both a new
 * assignee and the new owner hears once, as an assignee.
 */
class AnnounceLeadAssignmentAction
{
    public function __construct(private readonly Notifier $notifier) {}

    /**
     * @param  array<int, int>  $assigneeIds  Users newly assigned.
     * @param  int|null  $ownerId  The owner, when newly set or changed.
     * @param  User|null  $actor  Who made the change; null for an automation.
     */
    public function __invoke(Lead $lead, array $assigneeIds, ?int $ownerId, ?User $actor): void
    {
        $roles = [];

        foreach ($assigneeIds as $id) {
            $roles[(int) $id] = 'an assignee';
        }

        if ($ownerId !== null && ! isset($roles[$ownerId])) {
            $roles[$ownerId] = 'the owner';
        }

        // The actor chose this themselves. The engine drops an actor's own
        // notifications anyway; saying so here keeps the intent visible.
        if ($actor !== null) {
            unset($roles[$actor->id]);
        }

        if ($roles === []) {
            return;
        }

        DB::afterCommit(function () use ($lead, $roles, $actor): void {
            $users = User::query()->whereKey(array_keys($roles))->get();

            foreach ($users as $user) {
                $this->notifier->send(
                    'leads.assigned',
                    [Recipient::user($user, RecipientType::AssignedAgent)],
                    ['lead' => [
                        'name' => $lead->fullName(),
                        'company' => $lead->company_name ?? 'no company given',
                        'role' => $roles[$user->id],
                        'by' => $actor->name ?? 'An automation',
                    ]],
                    $actor,
                    route('leads.show', $lead->id),
                );
            }
        });
    }
}
