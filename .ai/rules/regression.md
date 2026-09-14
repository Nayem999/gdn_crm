---
paths:
  - 'tests/Feature/Regression/**'
  - 'app/Domain/Shared/RequestMemo.php'
  - 'app/Http/Middleware/SecurityHeaders.php'
---

# Regression sweeps

## These are sweeps on purpose
`tests/Feature/Regression` asserts the contracts in CRM_BUILD.md's testing
matrix across the **whole** application rather than module by module — every
policy, every select, every model's factory, every list screen's query shape.

A per-module test satisfies "every policy" only for the modules somebody
remembered, and the module that will break the rule is the next one, which
nobody will think to add a test for. These fail on their own.

When one of them fails for a new module, fix the module. The only case for
touching the sweep is a genuinely new *category* of exception — and then name
the exception in the test, one line each, rather than loosening the rule.

## The N+1 test counts, it does not name a number
A list screen is rendered with 3 records and then with 15. A screen that
eager-loads runs the same number of queries both times; one with an N+1 runs
more. Asserting a fixed query count instead would be asserting a number somebody
raises until the test passes.

Four real bugs came out of it in 11.3, and the worst was invisible from any
single module: `Company::current()` runs `select * from companies` and
`DisplayTime` calls it **every time a date is formatted** — so every screen with
a date column ran one query per row.

## RequestMemo, not `static` and not `once()`
`App\Domain\Shared\RequestMemo` is a container singleton, so its lifetime is one
web request, one queued job, or one test.

- A `static` property leaks between tests and between jobs in a long-running
  worker.
- Laravel's `once()` helper is **not** flushed between tests, so it leaks the
  same way — and a memoised Eloquent model from a rolled-back transaction is
  worse than a stale value.
- Binding the value into the container directly does not work either:
  `Container::make()` checks `isset()`, so a memoised **null** is
  indistinguishable from nothing bound and the container goes looking for a
  class of that name. "There is no default pipeline" is a real answer and has to
  be storable.

Anything memoised needs an invalidation hook. `Pipeline` and `Company` both
forget theirs in a `saved`/`deleted` model event, so a request that writes and
then reads gets what it wrote.

## What the security sweep locks down
- **The media disk is private.** medialibrary ships defaulting to `public`,
  which is symlinked into `public/storage` — every uploaded document would be
  readable by URL with `DocumentPolicy` never consulted. `DownloadDocument` is
  meant to be the only door, and `config/media-library.php` is what makes that
  true.
- **Document uploads are an allowlist** (`DocumentUploads`), not a denylist, and
  it excludes HTML, SVG and anything executable. Both of the first two run
  script when a browser renders them.
- **Downloads are always `attachment`.** Even from a private disk, an inline
  disposition on an `image/svg+xml` is script on this origin.
- **`SecurityHeaders`** is appended to every response. Laravel ships none of
  these, and each is an instruction only the browser can carry out. HSTS is sent
  only over a secure connection — from a local server it would pin a developer's
  browser to https for a site with no certificate.
- **The access-level fuzz** is the one the brief names outright: a user whose
  role sees only their own records cannot reach somebody else's by guessing an
  id, through any of `whereKey`, a key list, or `find`.

## Horizon's gate ships closed, not open
Laravel's stub is `in_array($user->email, [])` — which locks Horizon to nobody
at all in production, and reads as a permission fault rather than the
configuration one it is. It is gated on `settings.view` here.
