<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadAssignee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The one writer of `lead_assignees`.
 *
 * Two operations, because they answer different questions. `handle()` is the
 * editor's "here is the whole list now" — it replaces the set, the same way
 * saving a form replaces every field on it. `add()` is everything else that
 * hands a lead to one more person without disturbing who else is already
 * there: a capture form seeding its first assignee, an inbound record's
 * default owner, a workflow's "assign owner" step. Folding the second into
 * the first would mean every one of those callers first reading the current
 * list just to hand it back unchanged.
 *
 * **A lead can never end up with none.** That was `owner_id`'s invariant —
 * "a lead always has an owner; unassigned records are how visibility scoping
 * springs a leak" — and removing the column does not remove the reason it
 * existed: `Lead::scopeVisibleTo()` now reads this table, so a lead with zero
 * rows here is invisible to everyone below `all` access. Every operation here
 * refuses to leave that behind.
 */
class SyncLeadAssigneesAction
{
    /**
     * Replace the whole assignee set.
     *
     * @param  array<int, array{user_id: int, priority: ?int}>  $assignments
     *
     * @throws RuntimeException when the set would be empty, or names the same
     *                          user twice or one that does not exist
     */
    public function handle(Lead $lead, array $assignments): Lead
    {
        if ($assignments === []) {
            throw new RuntimeException('A lead needs at least one assignee.');
        }

        $userIds = array_column($assignments, 'user_id');

        if (count($userIds) !== count(array_unique($userIds))) {
            throw new RuntimeException('The same person cannot be assigned to a lead twice.');
        }

        $found = User::query()->whereIn('id', $userIds)->pluck('id')->all();

        if (count($found) !== count($userIds)) {
            throw new RuntimeException('One of the chosen people no longer exists.');
        }

        DB::transaction(function () use ($lead, $assignments) {
            $before = $this->summary($lead);
            $now = now();

            // Keyed by user, so an untouched row can be told apart from a
            // genuinely new or re-prioritised one.
            $existing = LeadAssignee::query()->where('lead_id', $lead->id)->get()->keyBy('user_id');
            $kept = [];

            foreach ($assignments as $assignment) {
                $current = $existing->get($assignment['user_id']);

                // assigned_at only moves for a row that is new here or whose
                // priority actually changed — not for one this save simply
                // repeats. Bumping every row on every save would mean adding
                // one more person to a lead resets everybody else's
                // escalation clock along with them, which is exactly the kind
                // of edit an admin makes without meaning to touch anyone
                // already on it.
                $row = LeadAssignee::query()->updateOrCreate(
                    ['lead_id' => $lead->id, 'user_id' => $assignment['user_id']],
                    $current !== null && $current->priority === $assignment['priority']
                        ? ['priority' => $assignment['priority']]
                        : ['priority' => $assignment['priority'], 'assigned_at' => $now, 'escalated_at' => null],
                );

                $kept[] = $row->id;
            }

            LeadAssignee::query()
                ->where('lead_id', $lead->id)
                ->whereNotIn('id', $kept)
                ->delete();

            $this->audit($lead, $before);
        });

        return $lead->refresh();
    }

    /**
     * Add or update one assignee without touching anybody else already on the
     * lead. Used by callers that only ever hand a lead to one more person: a
     * capture form's configured owner, an ingested record's default, a
     * workflow's "assign owner" action.
     */
    public function add(Lead $lead, User $user, ?int $priority = null): LeadAssignee
    {
        return DB::transaction(function () use ($lead, $user, $priority) {
            $before = $this->summary($lead);

            $row = LeadAssignee::query()->updateOrCreate(
                ['lead_id' => $lead->id, 'user_id' => $user->id],
                ['priority' => $priority, 'assigned_at' => now()],
            );

            $this->audit($lead, $before);

            return $row;
        });
    }

    /**
     * Take one person off a lead.
     *
     * @return bool False when they were not assigned to it in the first place.
     *
     * @throws RuntimeException when this would remove the last assignee
     */
    public function remove(Lead $lead, User $user): bool
    {
        return DB::transaction(function () use ($lead, $user): bool {
            $row = LeadAssignee::query()
                ->where('lead_id', $lead->id)
                ->where('user_id', $user->id)
                ->first();

            if ($row === null) {
                return false;
            }

            if (LeadAssignee::query()->where('lead_id', $lead->id)->count() <= 1) {
                throw new RuntimeException('A lead needs at least one assignee — assign somebody else first.');
            }

            $before = $this->summary($lead);

            $row->delete();

            $this->audit($lead, $before);

            return true;
        });
    }

    /**
     * "Name (priority), Name, ..." — read fresh so before/after actually
     * differ, and stable-ordered so an unrelated re-sort of the same set does
     * not read as a change.
     */
    private function summary(Lead $lead): string
    {
        return LeadAssignee::query()
            ->where('lead_id', $lead->id)
            ->with('user:id,name')
            ->orderByRaw('priority is null, priority')
            ->orderBy('assigned_at')
            ->get()
            ->map(fn (LeadAssignee $row) => $row->priority === null
                ? $row->user->name
                : $row->user->name.' ('.$row->priority.')')
            ->implode(', ');
    }

    private function audit(Lead $lead, string $before): void
    {
        AuditLogger::updated($lead, $lead->fullName(), ['assignees' => $this->summary($lead)], ['assignees' => $before]);
    }
}
