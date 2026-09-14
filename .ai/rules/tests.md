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

## The suite's memory limit is pinned in phpunit.xml
The whole suite runs in one process, so peak memory is the sum of everything it touches. PHP's 128M default stopped being enough during phase 4 and failed as a *fatal mid-run* — not as a test failure — which looks like a broken test rather than a broken limit.

`phpunit.xml` now pins `<ini name="memory_limit" value="512M"/>`. If `php artisan test` ever dies with "Allowed memory size exhausted" again, raise that rather than reaching for `-d memory_limit` at the call site, which does not reach the pest subprocess anyway.

## One test process at a time — the `testing` database is shared
`phpunit.xml` names a single database, so **every** suite on this machine
truncates and re-migrates the same one. Two runs at once destroy each other's
schema mid-flight, and the failures do not look like a collision: you get
`Base table 'password_reset_tokens' already exists` on one test and
`Table 'testing.migrations' doesn't exist` on the next, as the two processes
take turns dropping what the other just created.

A git worktree does **not** isolate this. A task spawned into
`.claude/worktrees/...` has its own checkout and its own `phpunit.xml`, both
pointing at `testing`. Check for a running suite before starting one:

    Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Select ProcessId, CommandLine

To run anyway, give the second process its own database — `<env>` in
`phpunit.xml` has no `force="true"`, so a real environment variable wins:

    DB_DATABASE=testing_p8 php artisan test tests/Feature/Ingestion

Create it first, and run the *gate* against `testing` once the other process is
done: a green run on a database nothing else has touched is not the same
assurance.

## Pest helper functions are global — name them for the module
A `function helper()` declared in any test file is declared for the **whole
suite**, so two files defining the same name is a fatal redeclare, not a
shadowed local. `tests/Feature/Ingestion` defined `integrationUser()` and
collided with `tests/Feature/CustomFields`, which had had one since Phase 6.

The trap is that a file-scoped run cannot see it: `php artisan test
tests/Feature/Ingestion` passed 61 tests, because the other file was never
loaded. Only the full run fails, and it fails as a PHP fatal rather than a test
failure.

So: prefix helpers with the module they belong to (`ingestionAdmin`,
`dealAdmin`, `activityAdmin`), and check **every** helper in a new file before
running the suite — not just the one that looks risky. Phase 8 hit this twice:
`integrationUser` against CustomFields, then `leadSource` against Duplicates and
`deliver` against Webhooks, in a file whose other helpers were already prefixed.

    for f in helperOne helperTwo helperThree; do
      n=$(grep -rl "function $f(" tests/ | wc -l)
      [ "$n" -gt 1 ] && echo "COLLIDES: $f"
    done

## `artisan test` is a parent; the run is the pest child
`php artisan test` spawns `vendor/pestphp/pest/bin/pest` and the parent exits
**first**. Waiting on the `artisan test` pid therefore reports "finished" while
the suite is still running, and starting the next run at that point collides
with it on the shared `testing` database — 513 failures in phase 9 from exactly
this, with the same `Table 'cache' already exists` /
`Table 'testing.migrations' doesn't exist` signature described above.

Check for the **pest** process, not the artisan one:

    Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
      Where CommandLine -like '*pest*' | Select ProcessId

## Do not pipe a suite run into `tail` or `sed`
Both buffer when stdout is not a terminal, so nothing appears for the whole run
and a backgrounded pipeline can end up writing an empty output file. Redirect to
a file and read the file afterwards:

    php artisan test --compact > <scratchpad>/gate.txt 2>&1

## Pest datasets here must be a list of arrays, not a flat list
`->with(['a', 'b'])` does **not** register in this project's Pest setup — the test fails with `DatasetMissing` ("has 1 argument and no dataset(s) provided"), and `->with(fn () => [...])` fails even more confusingly with "Typed static property ...::$__latestDescription must not be accessed before initialization". Neither error points at the dataset.

Write every dataset as a keyed list of argument arrays, the way OperationsTest does:

    ->with(['pint' => ['pint --test'], 'tests' => ['artisan test']]);
