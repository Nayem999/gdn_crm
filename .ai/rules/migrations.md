---
paths:
  - 'database/migrations/**'
---

# Migrations

## Access-level scoping lives on spatie/permission's roles table
Record-level visibility (own/team/all) is a column on the existing spatie/laravel-permission `roles` table (`data_access_level`, string, default 'own'), added via a separate migration rather than a duplicate custom roles table. Do not create a second roles table. The `ScopesByAccessLevel` trait (Phase 1.1) reads this column off the user's role(s).

Team membership follows the Jetstream pattern: `users.current_team_id` (nullable FK, null on delete) is the user's active team, and `team_user` is a many-to-many pivot for broader membership/"user groups". `teams` self-references via nullable `parent_id` for department hierarchy.

## A NOT NULL timestamp() column gets ON UPDATE CURRENT_TIMESTAMP — use dateTime()
MySQL and MariaDB hand the **first NOT NULL TIMESTAMP column** in a table an
implicit `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`, unless
`explicit_defaults_for_timestamp` is on (it is not, here). So any later UPDATE of
that row silently rewrites the column to the server clock.

3.4 hit this: `deal_stage_entries.entered_at` was `timestamp()`, and closing a
visit — an UPDATE setting `left_at` — moved `entered_at` to now. Every duration
was wrong, with nothing in the logs. The tests caught it because they froze the
clock; a test using real time would have passed.

So: **`$table->dateTime()` for any non-nullable point in time that is not
`created_at`/`updated_at`.** DATETIME never gets that treatment, and it has no
2038 ceiling. A *nullable* `timestamp()` is exempt from the rule, which is why
`deals.closed_at` and the rest are fine.

`DealStageHistoryTest` pins the column type and asserts an update leaves
`entered_at` alone.
