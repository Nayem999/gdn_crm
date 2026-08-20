---
paths:
  - '*'
---

# General

## Local dev DB is MariaDB, not MySQL 8 — stack targets MySQL only
This project's stack mandates MySQL 8.0+ only (no MariaDB, no Postgres, no SQLite outside tests). The XAMPP install on this dev machine bundles MariaDB 10.4, not real MySQL. Migrations have been verified to run/rollback cleanly against it, but do not rely on MySQL-8-only features (e.g. functional indexes, some JSON_TABLE behavior, CTE edge cases) without checking they also work here. Production and CI must run real MySQL 8+.
