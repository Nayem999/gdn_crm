---
paths:
  - 'app/Domain/*/Models/*.php'
---

# Models

## Use ScopesByAccessLevel on every business model, query via visibleTo()
Every business model with owner-based visibility (Leads, Deals, Contacts, ... starting Phase 2) must `use App\Domain\Shared\Concerns\ScopesByAccessLevel;` and be queried through `Model::visibleTo($user)`, never a hand-rolled `where('owner_id', ...)`.

The trait assumes an `owner_id` column by default; if a model's owner column is named differently, override it with `protected function accessLevelOwnerColumn(): string`.

"Team" level matches on `users.current_team_id` equality (the user's single active team), not the `team_user` many-to-many pivot — see app/Domain/Shared/Concerns/ScopesByAccessLevel.php. A user with no `current_team_id` under "team" access falls back to seeing only their own records (never a broad "all other teamless users" match).

phpstan.neon currently ignores `trait.unused` for this file since no app/ model consumes it yet (Task 1.1 built it ahead of Phase 2). Remove that ignoreErrors entry the moment a real model adds `use ScopesByAccessLevel`.

## applyScopes() before getQuery(), or soft-deleted rows come back
Eloquent applies its global scopes at **execution** time, not when the builder is constructed. So `$builder->getQuery()` — the usual way to drop to the base query builder for a hand-written aggregate — silently discards them, and every model here that soft-deletes then counts its deleted rows.

Write `$builder->applyScopes()->getQuery()` whenever you need the base builder. `PipelineFunnel::totals()` does this and says why.

This is not hypothetical: `DealsIndex::totals()` has the bug today — the count and value under the deals list include removed deals, so the footer disagrees with the rows above it. A test that only ever creates live records will not catch it; delete one in the fixture.

The same trap applies to `toBase()`, and to passing an Eloquent builder somewhere that calls `getQuery()` on it. `whereIn($column, $eloquentBuilder)` is safe — it goes through `toSql()`, which does apply scopes.
