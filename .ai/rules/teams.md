---
paths:
  - 'app/Domain/Teams/**'
---

# Teams

## Team membership changes must keep current_team_id honest
`users.current_team_id` is what ScopesByAccessLevel reads for "team" access level, and it is NOT maintained by the `team_user` pivot on its own. Detaching a user from a team leaves that column pointing at a team they no longer belong to, so they keep seeing its records — a visibility leak.

Always change membership through App\Domain\Teams\Actions\SyncTeamMembersAction, never `$team->users()->sync()` directly. It:
- clears current_team_id for anyone removed whose active team was this one
- sets current_team_id for anyone added who had none

Deletion is handled by the schema instead (current_team_id and parent_id are nullOnDelete, team_user cascades), so sub-teams get promoted to the root and ex-members fall back to own-only visibility.

Hierarchy: a team may not be its own parent or sit beneath its own descendant. UpdateTeamAction enforces it and TeamForm::parentOptions() omits the invalid choices. Team::ancestors() tracks visited ids so pre-existing cyclic data can't loop.
