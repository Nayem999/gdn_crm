---
paths:
  - 'database/migrations/**'
---

# Migrations

## Access-level scoping lives on spatie/permission's roles table
Record-level visibility (own/team/all) is a column on the existing spatie/laravel-permission `roles` table (`data_access_level`, string, default 'own'), added via a separate migration rather than a duplicate custom roles table. Do not create a second roles table. The `ScopesByAccessLevel` trait (Phase 1.1) reads this column off the user's role(s).

Team membership follows the Jetstream pattern: `users.current_team_id` (nullable FK, null on delete) is the user's active team, and `team_user` is a many-to-many pivot for broader membership/"user groups". `teams` self-references via nullable `parent_id` for department hierarchy.
