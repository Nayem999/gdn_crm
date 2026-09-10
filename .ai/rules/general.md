---
paths:
  - '*'
---

# General

## Local dev DB is MariaDB, not MySQL 8 — stack targets MySQL only
This project's stack mandates MySQL 8.0+ only (no MariaDB, no Postgres, no SQLite outside tests). The XAMPP install on this dev machine bundles MariaDB 10.4, not real MySQL. Migrations have been verified to run/rollback cleanly against it, but do not rely on MySQL-8-only features (e.g. functional indexes, some JSON_TABLE behavior, CTE edge cases) without checking they also work here. Production and CI must run real MySQL 8+.

## The built CSS size depends on the Blade view cache, and that is expected
`resources/css/app.css` lists `@source '../../storage/framework/views/*.php'`, so
`npm run build` scans whatever Blade happens to have compiled. Running
`php artisan view:clear` first therefore produces a *smaller* stylesheet — 65KB
against 81KB at one point in Phase 3 — and the difference is dead classes from
framework error pages and deleted views, not anything the app renders.

Tailwind v4 auto-detects sources from the project root as well, which is what
covers `app/**` (where ChipPalette writes its class names out in full). So a
cold-cache build is the complete one. If the size drops after a `view:clear`,
that is the correct output; confirm by building the same tree twice rather than
assuming a regression.
