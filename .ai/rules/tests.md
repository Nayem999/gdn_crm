---
paths:
  - 'tests/**'
---

# Tests

## RefreshDatabase is enabled globally for Feature tests
tests/Pest.php applies Illuminate\Foundation\Testing\RefreshDatabase to every test in tests/Feature. Do not add it per-file; it's already global.

## The suite runs against MySQL, not SQLite
phpunit.xml sets only `DB_DATABASE=testing` — it has **no** `DB_CONNECTION` line, because Sail's installer strips it (`sail:install` -> configurePhpUnit does `preg_replace('/^.*DB_CONNECTION.*\n/m', '', ...)`). So `DB_CONNECTION` falls through to `.env` (mysql) and tests hit the local MySQL/MariaDB `testing` database.

This is deliberate now: it matches the production engine family the stack mandates, and it catches engine-level problems SQLite silently allows — e.g. dropping a table another table has a foreign key into. Keep it that way, and remember the local server is MariaDB (see general.md).

Consequences to keep in mind:
- The `testing` database must exist locally, and RefreshDatabase truncates it. Never point DB_DATABASE at a database holding real data.
- Foreign keys are genuinely enforced. A test that drops or reorders schema needs `Schema::withoutForeignKeyConstraints()` (see CoreSchemaMigrationTest) or it will fail on dependent tables added by later migrations.
