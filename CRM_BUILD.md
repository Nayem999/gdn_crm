# CRM Build Prompt — Laravel 12 / PHP 8.2

> Paste this whole file into your CLI agent as the project brief (or save it as `CRM_BUILD.md` in the repo root and tell the agent: *"Read CRM_BUILD.md and start at Phase 0, Task 0.1."*).

---

## ROLE

You are a senior Laravel engineer building a single-organization CRM from scratch — one company, one installation, one database. There is no multi-tenancy: do not add `tenant_id`, tenant scopes, tenant middleware, or subdomain resolution anywhere. You work **one task at a time**, in the exact order listed in the Phase Plan. You do not skip ahead, you do not bundle tasks, and you do not move to the next task until the current one is fully complete, tested, and committed.

---

## STACK — DO NOT SUBSTITUTE

| Layer | Technology |
|---|---|
| Framework | Laravel 12 |
| Language | PHP 8.2 (strict types, typed properties, enums, readonly where sensible) |
| Database | **MySQL 8.0+ only** — InnoDB, `utf8mb4_unicode_ci`. No PostgreSQL, no SQLite in production (SQLite permitted for the test suite only) |
| Cache / Queue | Redis + Laravel Horizon |
| UI | Livewire 3 + Alpine.js 3 + Tailwind CSS |
| Dropdowns | Tom Select (Select2-style: type-ahead search, remote loading, tagging, multi-select) |
| Mail | Driver-based multi-provider: SMTP, Mailgun, Brevo, SendGrid, Amazon SES, Postmark |
| Credentials | Laravel `Crypt` — all integration secrets encrypted at rest, configured from Settings |
| Icons | Lucide icons (colorful, per-module color tokens) |
| Auth | Laravel Fortify + Sanctum (API) |
| Permissions | spatie/laravel-permission |
| Activity log | spatie/laravel-activitylog |
| Media | spatie/laravel-medialibrary |
| Excel/CSV | maatwebsite/excel |
| PDF | barryvdh/laravel-dompdf |
| Drag & drop | SortableJS (Kanban) |
| Tests | Pest 3 (feature + unit), Laravel factories |
| Quality | Laravel Pint, Larastan level 6 |

---

## WORKING AGREEMENT — READ BEFORE EVERY TASK

1. **Announce the task** you are starting by ID and title.
2. **Plan first**: list the files you will create/modify before writing code.
3. **Build the task** completely — migration, model, policy, service, Livewire component, views, routes, seeder.
4. **Write tests for that task** (see Testing Contract below).
5. **Run the full gate**:
   ```bash
   php artisan test
   ./vendor/bin/pint --test
   ./vendor/bin/phpstan analyse
   ```
6. **Fix everything until green.** Never report a task as done with a failing test.
7. **Commit** with `feat(P{phase}-{task}): {description}`.
8. **Report**: what was built, what tests were added, test count passing, then **stop and wait** for approval before the next task.

**Rules:**
- No task is complete without passing tests.
- Never modify a previous task's tests to make a new task pass — fix the code.
- If a requirement is ambiguous, ask before building. Do not guess on business logic.
- Every new module must satisfy the full UI Standard below — no exceptions, no "TODO later".

---

## ARCHITECTURE RULES

- **Single-organization install.** No `tenant_id` on any table. Record-level visibility is controlled entirely by the **data access level** on the user's role — own / team / all — applied through a `ScopesByAccessLevel` trait and query scope, never by ad-hoc `where()` calls in controllers.
- **Modular structure**: `app/Domain/{Module}/{Models,Actions,Services,DTOs,Enums,Policies}`.
- **Actions over fat controllers**: one invokable action class per business operation (`ConvertLeadAction`, `MoveDealStageAction`).
- **Enums for all statuses** (`LeadStatus`, `DealStage`, `TicketPriority`) — never raw strings.
- **Policies on every model**; authorize in Livewire `mount()` and every action method.
- **Custom fields are data, not columns** — a polymorphic `custom_field_values` table resolved through a `HasCustomFields` trait.
- **Every list query must be paginated and indexed.** No unbounded `->get()` on business tables.
- **MySQL conventions**: InnoDB on every table; `utf8mb4_unicode_ci` collation; foreign keys with explicit `onDelete` behaviour; an index on every field exposed in the filter builder, plus composite indexes on common pairs such as `(owner_id, status)` and `(status, created_at)`; `DECIMAL(15,2)` for all money — never `FLOAT`; `JSON` columns only for schemaless settings/preferences, never for anything that must be filtered or joined.
- **Inbound data is untrusted**: capture raw, respond fast, process on the queue, validate against the mapping schema, never trust a payload field to name a column or class.
- **Queue anything slow**: imports, exports over 1,000 rows, emails, webhooks, report generation.

---

## UI STANDARD — APPLIES TO EVERY MODULE

Every list screen (Leads, Contacts, Accounts, Deals, Activities, Products, Quotes, Invoices, Tickets) must ship with all five capabilities below. Build them **once** as reusable components in Phase 1, then reuse.

### 1. Loading states
- Skeleton loaders on initial page load (shimmer rows matching the active view's shape — table rows, kanban cards, grid tiles).
- `wire:loading` spinner on every filter change, sort, page change, and search.
- Buttons show an inline spinner and become disabled while their action runs (`wire:loading.attr="disabled"`).
- A thin top progress bar for any request over 300 ms.
- Optimistic UI on Kanban drag — card moves instantly, reverts with a toast on failure.
- Distinct **empty state** (illustration + "Create your first lead" CTA) and **error state** (message + Retry button). Never show a blank screen.

### 2. View switcher — List / Kanban / Grid / Table
A segmented control in the page header switches between four modes. The selected mode is **saved per user, per module** in a `user_view_preferences` table and restored on return.

- **Table** — the primary view. Sortable columns, sticky header, row selection with checkbox + "select all matching filter", bulk action bar, inline row actions, resizable columns, pagination with page-size selector (25/50/100).
- **Manage Columns** — a modal/drawer listing all available fields (including custom fields) with: show/hide toggles, drag-to-reorder, pin left, reset to default. Saved per user per module.
- **Kanban** — grouped by a configurable field (deal stage, lead status, ticket status). Drag & drop between columns triggers the status update. Column headers show record count and summed value where relevant. Lazy-load 20 cards per column with "load more".
- **Grid** — card layout with avatar/logo, primary fields, owner, status chip, and quick actions.
- **List** — compact single-line rows for dense scanning on smaller screens.

All four views respect the same filter state, search term, and sort.

### 3. Filtering
- Always-visible search box (debounced 300 ms) searching the module's key fields.
- Quick filter chips (My Records, Unassigned, Created This Week, Overdue).
- **Advanced filter builder**: add condition rows of `field → operator → value`, combine with AND/OR, nest groups. Operators adapt to field type (text: contains/equals/starts with; number: =, >, <, between; date: on/before/after/between/last N days; select: is/is not/is any of; boolean; is empty/is not empty).
- Custom fields appear in the filter builder automatically.
- **Saved filters/views**: name and save a filter set, mark one as default, share with team (permission-gated).
- Active filters render as removable chips with a "Clear all" button.
- Filter state is reflected in the URL query string so views are shareable and bookmarkable.

### 4. Download & Print
- Export button offering **CSV, Excel (.xlsx), PDF**.
- Export respects the **current filters, sort, and visible column set**; option to export selected rows only or all matching records.
- Exports over 1,000 rows are queued and delivered by notification with a download link.
- **Print** produces a clean printable layout via a dedicated print stylesheet — no nav, no buttons, repeated table headers across pages, footer with filter summary, record count, and timestamp.
- Detail records (Quote, Invoice, Sales Order, Ticket) get a branded PDF using the company logo from Settings.

### 5. Colorful icons
- Lucide icon set throughout; each module has its own color token used consistently in navigation, page headers, and empty states:
  - Leads `amber` · Contacts `blue` · Accounts `indigo` · Deals `emerald` · Activities `violet` · Products `orange` · Quotes/Invoices `cyan` · Support `rose` · Reports `teal` · Automation `fuchsia` · Settings `slate`
- Icons render as a colored chip: soft tinted background + solid icon (`bg-{color}-100 text-{color}-600`, dark-mode variants required).
- Status chips are color-coded by enum value, defined once in the enum's `color()` method — never hardcoded in Blade.
- Priority, score, and stage indicators use consistent color semantics across all modules.
- All icons carry `aria-label` or accompanying text; color is never the only signal.

### 6. Searchable dropdowns — every select, everywhere
No plain `<select>` anywhere in the application. Build one `<x-select>` component (Tom Select based) used by every form, filter, and modal:
- **Type-ahead search** inside the dropdown, filtering as the user types.
- **Server-side search** for any list that can exceed 50 options (accounts, contacts, users, products) — debounced AJAX endpoint returning paginated results, with infinite scroll inside the dropdown.
- **Static client-side search** for small fixed lists (status, priority, country).
- **Multi-select** with removable chips.
- **Create-on-the-fly**: "+ Add new" inside the dropdown opens a quick-create modal, and the new record is auto-selected on save (permission-gated).
- **Dependent/cascading selects**: Country → State → City, Account → Contact, Pipeline → Stage. Child clears and reloads when the parent changes.
- **Rich options**: avatar/logo, subtitle line, colored status dot where relevant.
- Loading spinner inside the dropdown while fetching; "No results found" empty state; keyboard navigation and full accessibility.
- Custom-field select and multiselect types use the same component automatically.

---

## TESTING CONTRACT — EVERY TASK ENDS HERE

Each task must add tests before it can be called done:

| Test type | Required for |
|---|---|
| Feature test (HTTP/Livewire) | Every CRUD screen: create, read, update, delete, validation failures |
| Authorization test | Every policy: owner can, non-owner cannot, unauthorized user gets 403/404 |
| Access-level test | Every module — `own` sees only their records, `team` sees the team's, `all` sees everything |
| Unit test | Every Action, Service, calculation, enum helper |
| View-mode test | Table/Kanban/Grid/List each render with data and empty |
| Filter test | Each operator returns the correct record set |
| Column preference test | Hidden columns persist and reload per user |
| Export test | CSV/Excel/PDF generate and respect active filters |
| Queue test | Queued jobs dispatch with correct payload |
| Dropdown test | Every select uses `<x-select>`; server-side search returns paginated matches; cascading selects clear and reload |
| Settings test | Values save, cache invalidates, secrets encrypt at rest and never appear in responses or logs, non-admins cannot read or write them |
| Provider test | Each mail/SMS driver sends through a faked transport; switching the active provider switches the transport |
| Notification test | Each event notifies the right recipients on the right channels; matrix toggles and user overrides respected |
| Ingestion test | Valid key accepted and invalid rejected; signature and replay protection enforced; duplicate delivery is idempotent; mapping produces the expected record; failed events replay successfully |

Minimum: **80% coverage on `app/Domain`**. Factories required for every model. Use `RefreshDatabase`.

Definition of Done for any task:
- [ ] Feature works end to end in the browser
- [ ] All four view modes work (where the task is a list module)
- [ ] Loading, empty, and error states present
- [ ] Filters, column manager, export, print working
- [ ] All dropdowns are searchable `<x-select>` components
- [ ] Any credential the task introduces is admin-configurable in Settings, encrypted, never in `.env`
- [ ] Tests written and passing
- [ ] Pint clean, PHPStan level 6 clean
- [ ] Committed with the correct message format

---

## SETTINGS & CREDENTIALS — EVERYTHING ADMIN-CONFIGURABLE

**Hard rule: no integration credential is ever read from `.env` or hardcoded.** Every provider key, secret, token, sender address, endpoint and toggle is configured by an administrator through the Settings UI, stored encrypted in the database, and resolved at runtime.

### Settings framework
- `settings` table: `group`, `key`, `value` (encrypted when flagged secret), `type`, `is_secret`.
- Typed accessor `settings('mail.mailgun.secret')` with cache, invalidated on save.
- Secrets are **write-only in the UI** — display as `••••••••` with a "Replace" action; never echo a stored secret back to the browser or into logs.
- Every settings group has a **Test Connection** button that performs a real call and reports success/failure with the provider's error message.
- Changing a provider's credentials logs an audit entry (who, when, which key — never the value).

### Settings groups to build
| Group | Contents |
|---|---|
| Company | Name, logo, address, timezone, currency, date format, fiscal year, number formats |
| Email Providers | SMTP, Mailgun, Brevo, SendGrid, Amazon SES, Postmark — see below |
| Email Accounts | IMAP/inbound sync per user or shared mailbox, sync frequency |
| SMS Providers | Twilio, Vonage, local gateway — SID/key/secret/sender ID |
| WhatsApp | Business API token, phone number ID, template IDs |
| Telephony | Provider key, caller ID, recording toggle |
| Chat | Website widget key, routing rules |
| Notifications | Per-event channel matrix (see Notification Engine) |
| API & Webhooks | API keys, OAuth clients, outbound webhook endpoints, signing secret, retry policy |
| Data Sources | Inbound ingest endpoints + secret keys, pull-sync connections, field mapping, dedupe and assignment rules (see Inbound Data Gateway) |
| Marketing | Mailchimp / Meta / Google Ads keys |
| Storage | S3 bucket, region, keys (fallback to local disk) |
| Billing | Payment gateway keys |

### Email provider architecture
- One `MailProviderInterface`; one driver class per provider (`SmtpDriver`, `MailgunDriver`, `BrevoDriver`, `SendGridDriver`, `SesDriver`, `PostmarkDriver`).
- Admin selects the **active provider** in Settings and fills only that provider's fields; the form swaps fields dynamically per provider.
- Runtime mailer configuration is built from the stored settings — switching provider in Settings takes effect immediately, with no `.env` change and no redeploy.
- Per-provider fields: SMTP (host, port, encryption, username, password), Mailgun (domain, secret, endpoint/region), Brevo (API key), SendGrid (API key), SES (key, secret, region), Postmark (server token). All plus: from-name, from-address, reply-to.
- **Fallback provider** (optional): if the primary fails, retry through the secondary and raise an admin alert.
- Send test email, delivery log with status (queued/sent/bounced/failed/opened/clicked), bounce and complaint webhook handling per provider.
- Provider-agnostic sending API used by the rest of the app: `Mailer::send($mailable)` — no module ever references a provider directly.

---

## NOTIFICATION ENGINE

A single engine serving in-app, email, SMS and WhatsApp notifications, driven by an admin-configurable event/channel matrix.

- `notification_settings` matrix: for each event × recipient type (customer / assigned agent / admin / watcher), toggle each channel on or off.
- Per-user overrides in profile preferences (a user may mute in-app but keep email).
- Templates per event per channel, editable in Settings, with merge fields (`{{ticket.number}}`, `{{ticket.status}}`, `{{contact.name}}`, `{{agent.name}}`).
- All notifications dispatch through the queue; failures retried and logged in a notification log viewer.
- Quiet hours and global rate limiting to prevent notification storms.
- In-app notification bell: unread badge, dropdown list, mark read/all read, deep link to the record.

---

## INBOUND DATA GATEWAY — THIRD-PARTY DATA INGESTION

External systems must be able to feed records into the CRM automatically, authenticated by a secret key. Example the client requires: **when a task or project is created on their other website, that record is pushed here and becomes a Lead.**

Supports two directions, both configured entirely from Settings → Data Sources:

### A. Push mode (third party → CRM)
- Each data source gets a **unique endpoint** `POST /api/ingest/{source_uuid}` and a **generated secret key**.
- Secret is shown **once** at creation, stored hashed, and can be rotated or revoked. Rotating keeps the old key valid for a configurable grace window.
- Authentication options per source: `X-CRM-Key` bearer secret, and/or **HMAC-SHA256 signature** over the raw body with a timestamp header and a ±5-minute replay window.
- Optional IP allowlist, per-source rate limit, and max payload size.

### B. Pull mode (CRM → third party)
- Configure endpoint URL, method, auth (bearer / API key header / basic / OAuth2), query params, and headers.
- Pagination strategy: page number, offset, or cursor. Incremental sync via an `updated_since` cursor persisted per source.
- Schedule via cron expression; manual "Sync now" button; per-run summary.

### Shared ingestion pipeline (identical for both modes)
1. **Capture** — write the raw payload to `integration_events` (source, headers, body, hash, received_at) and return `202 Accepted` immediately. Never process inline.
2. **Queue** — a job picks up the event and runs the rest.
3. **Filter** — optional conditions; payloads that don't match are logged as `skipped`, not failed.
4. **Map** — field mapping from JSON path to CRM field, including custom fields. Built in a UI, not in code.
5. **Transform** — default values, value maps (`"project"` → source `Website Project`), date-format parsing, split full name into first/last, concatenate, trim, case conversion, number/currency casting.
6. **Deduplicate** — match on `external_id` + source, or email, or phone. Action: create new / update existing / skip / create-and-link.
7. **Persist** — create or update the target record (Lead, Contact, Account, Deal, Ticket, or any custom module) inside a transaction, storing `external_id`, `source_id` and the raw payload reference on the record.
8. **Assign** — fixed owner, round-robin, load-based, or rule-based.
9. **Trigger** — fire the workflow engine (Phase 5) so ingestion can start automations: notify the sales rep, send a welcome email, create a follow-up task.
10. **Log** — every event ends in the log with status `success / skipped / failed`, the mapped output, a link to the created record, and the error if any.

### Mapping UX — this must be usable by an admin, not a developer
- **Listen mode**: put a source in listening state, have the third party fire one real request, and the CRM captures the payload and **auto-suggests the field mapping** from it.
- Sample payload viewer with clickable JSON paths that populate the mapping rows.
- **Test run** against the captured sample showing exactly what record would be created — without creating it.
- **Sandbox mode** per source: process and log everything but persist nothing.

### Reliability & operations
- Idempotency key = source + `external_id` (or body hash) — a duplicate delivery never creates a duplicate record.
- Retry with exponential backoff (3 attempts), then dead-letter.
- **Manual replay** of any logged event, single or bulk, after fixing a mapping.
- Admin alert (notification engine) after N consecutive failures or on auth failures.
- Health indicator per source: last success, last failure, success rate over 24h, events today.
- Retention policy for raw payloads (configurable, default 90 days).

### Security rules
- Secrets hashed at rest and compared in constant time; never logged, never returned by the API.
- Signature verification before the body is parsed.
- Payloads are data only — never evaluate, unserialize, or execute anything from a payload.
- Ingested content is sanitized before storage and escaped on render.
- A source can only write into the target module it is configured for; the payload can never redirect it to a different module or model.
- Failed auth attempts are rate-limited and logged with IP.

### Platform presets
Ship ready-made presets (endpoint shape + suggested mapping) for: generic JSON webhook, WordPress / WooCommerce, Shopify, Google Forms, Facebook Lead Ads, Jira, Trello, Asana, ClickUp, and a **custom project/task system** matching the client's own website.

---

## PHASE PLAN — BUILD IN THIS ORDER

### Phase 0 — Foundation Setup
- **0.1** Laravel 12 install, PHP 8.2 config, Pest, Pint, Larastan, `.env.example`, Docker/Sail. Test: app boots, `php artisan test` green on the default suite.
- **0.2** Package installation and config publish (all packages listed in Stack). Test: config files exist, service providers register.
- **0.3** Database ERD implemented as migrations for core tables only (users, teams, roles, settings). Test: migrations run and roll back cleanly.
- **0.4** Tailwind design system — color tokens, typography, spacing, dark mode, base layout shell with sidebar + topbar. Test: layout renders, dark mode toggles.

### Phase 1 — Organization & User Management + Shared UI Kit
- **1.1** Company profile (single organization record: name, logo, address, timezone, currency, fiscal year) + `ScopesByAccessLevel` trait and query scope used by every business model. Test: company profile saves; each access level returns exactly the expected record set.
- **1.2** Authentication (login, register, password reset, 2FA), session security, login history. Test: full auth flow + 2FA challenge.
- **1.3** Users CRUD, invitations, profile, avatar upload. Test: CRUD + invitation email queued.
- **1.4** Teams / departments, user groups. Test: membership assignment and scoping.
- **1.5** Roles & permissions matrix UI, data access levels (own / team / all). Test: each access level returns the correct record set.
- **1.6** Audit log (activitylog) with a viewer screen. Test: create/update/delete each write a log entry.
- **1.7** **Shared UI kit** — build these reusable Livewire/Blade components now: `<x-data-view>` (list/kanban/grid/table switcher), `<x-column-manager>`, `<x-filter-builder>`, `<x-export-menu>`, `<x-print-layout>`, `<x-skeleton>`, `<x-empty-state>`, `<x-error-state>`, `<x-status-chip>`, `<x-icon-chip>`, `<x-bulk-actions>`. Test: each component renders in isolation; view preference persists; column preference persists; every filter operator returns correct results.
- **1.8** Settings framework — `settings` table, typed accessor, cache, encrypted secret handling, write-only secret fields, Settings shell UI with group navigation, audit logging on change. Test: values save and read back; secrets encrypt at rest; secrets never appear in responses or logs; cache invalidates on save; non-admin users are denied read and write access to secret settings.
- **1.9** Notification engine core — channel drivers (in-app, email, SMS, WhatsApp), event/channel matrix, per-user overrides, templates with merge fields, queued dispatch, notification log, in-app bell UI. Test: each channel dispatches; matrix toggles respected; user override wins; merge fields resolve; failures logged and retryable.

> **Gate:** Do not start Phase 2 until 1.7 is complete. Every later module consumes these components — including the `<x-select>` searchable dropdown, which must be in place before any form is built.

### Phase 2 — Leads, Contacts & Accounts
- **2.1** Accounts/Companies — profile, industry, size, revenue, owner, hierarchy (parent/child). Test: CRUD, hierarchy integrity, access-level scoping, all 4 views.
- **2.2** Contacts — profile, multiple contacts per account, primary contact flag. Test: CRUD, account relation, all 4 views.
- **2.3** Leads — capture, database, sources, status enum, owner assignment. Test: CRUD, status transitions, all 4 views + Kanban by status.
- **2.4** Lead scoring & qualification rules. Test: score calculation across rule combinations.
- **2.5** Duplicate detection on email/phone/company with merge flow. Test: duplicates detected, merge preserves history.
- **2.6** Lead conversion → Account + Contact + Deal in one transaction. Test: conversion creates all three, is idempotent, rolls back on failure.
- **2.7** Import/export with field mapping, validation preview, error report. Test: valid rows import, invalid rows reported, large file queues.
- **2.8** Record timeline — notes, documents, history on Leads/Contacts/Accounts. Test: timeline ordering and polymorphic relations.

### Phase 3 — Deals, Activities & Dashboard
- **3.1** Pipelines and stages (multiple pipelines, configurable stages, probability per stage). Test: pipeline CRUD, stage reordering.
- **3.2** Deals — value, expected close date, owner, products attached, win/loss reasons. Test: CRUD, weighted value calculation, all 4 views.
- **3.3** **Kanban pipeline** with drag & drop stage moves, optimistic UI, column totals. Test: drag updates stage, logs history, reverts on failure.
- **3.4** Deal history and stage-duration tracking. Test: history entry per stage change with correct timestamps.
- **3.5** Activities — tasks, calls, meetings with due dates, reminders, recurrence. Test: CRUD, recurrence generation, reminder jobs queued.
- **3.6** Calendar view (month/week/day) + activity timeline + meeting booking. Test: events render in the right slots, timezone-safe.
- **3.7** Main dashboard — KPI cards, pipeline funnel, activity feed, my tasks, with skeleton loading. Test: widgets render with and without data, respect data access level.

### Phase 4 — Customization Engine
- **4.1** Custom fields (text, number, date, select, multiselect, checkbox, currency, lookup) with `HasCustomFields` trait. Test: values save/load per type; validation per type.
- **4.2** Custom field integration into forms, table columns, filter builder, exports. Test: a new custom field appears in all four places automatically.
- **4.3** Custom layouts and conditional field visibility. Test: conditions show/hide correctly.
- **4.4** Custom statuses and custom pipelines per module. Test: custom status flows through Kanban and filters.
- **4.5** Saved views (per user and shared) with default view selection. Test: saved view restores filters, columns, sort, and view mode.
- **4.6** Custom modules generator — define a module, get table + CRUD + all four views + filters + export. Test: generated module passes the same standard test suite.
- **4.7** Form builder for lead capture with public embeddable form. Test: submission creates a lead, spam/honeypot rejected.

### Phase 5 — Workflow & Automation
- **5.1** Workflow schema — trigger, conditions, actions, execution log. Test: schema CRUD.
- **5.2** Trigger engine (on create/update/delete, field change, date-based, scheduled). Test: each trigger type fires exactly once.
- **5.3** Condition evaluator (AND/OR groups, all operators). Test: truth table across operator combinations.
- **5.4** Action library — update field, create record, assign owner, send email, send notification, call webhook. Test: each action executes and logs.
- **5.5** Visual workflow builder UI. Test: builder saves a valid definition and round-trips it.
- **5.6** Approval workflows, multi-step, escalation rules. Test: approve/reject paths, escalation on timeout.
- **5.7** Automated assignment (round-robin, load-based, territory). Test: distribution correctness over N records.
- **5.8** Workflow execution log viewer with retry. Test: failures logged and retryable.

### Phase 6 — Products & Sales Documents
- **6.1** Products, services, price books, product bundles. Test: CRUD, price resolution by price book.
- **6.2** Line items engine with discounts and taxes. Test: totals across discount/tax combinations, rounding correctness.
- **6.3** Quotes — builder, versioning, status flow, branded PDF, email send. Test: PDF generates, totals match, status transitions valid.
- **6.4** Sales orders and purchase orders. Test: conversion from quote preserves line items.
- **6.5** Invoices with payment status tracking. Test: partial and full payment states.
- **6.6** Basic CPQ rules (quantity breaks, bundle pricing, approval on max discount). Test: rules trigger at correct thresholds.

### Phase 7 — Communication & Integrations
- **7.1** `MailProviderInterface` + drivers for SMTP, Mailgun, Brevo, SendGrid, SES and Postmark; runtime mailer built from stored settings; optional fallback provider. Test: each driver sends through a faked transport; changing the active provider in Settings changes the transport without a config cache clear; fallback engages when the primary throws.
- **7.2** Email Providers settings screen — dynamic per-provider field set, encrypted storage, masked secrets, **Test Connection** and **Send Test Email** buttons. Test: invalid credentials surface the provider error; valid credentials pass; secrets never returned to the client.
- **7.3** Delivery log + bounce/complaint/open/click webhook handlers per provider. Test: each provider's webhook payload updates the correct message status.
- **7.4** Inbound email (IMAP) sync, thread matching to contacts/leads/tickets. Test: inbound message attaches to the right record; duplicates ignored.
- **7.5** Email templates with merge fields, tracking pixel and link tracking. Test: merge fields resolve, opens and clicks log.
- **7.6** SMS and WhatsApp drivers behind a channel interface, credentials from Settings, test-send buttons. Test: driver contract with a fake driver; credentials resolved from Settings, not `.env`.
- **7.7** Website chat capture → lead. Test: conversation creates/updates a lead.
- **7.8** Unified communication timeline across all channels. Test: chronological merge of email/SMS/call/chat.
- **7.9** REST API — resources, Sanctum tokens, API keys, rate limiting, versioning; keys managed in Settings. Test: auth required, rate limit enforced, access level respected.
- **7.10** Outbound webhooks with retry and signature, endpoints configured in Settings. Test: retries on failure, signature verifies.
- **7.11** API documentation generation. Test: docs build without errors.

### Phase 8 — Inbound Data Gateway
- **8.1** `data_sources` and `integration_events` schema; source CRUD in Settings with type (push/pull), target module, enable/disable, sandbox toggle. Test: CRUD, admin-only access, disabled source rejects requests.
- **8.2** Secret key generation, hashing, one-time display, rotation with grace window, revoke. Test: valid key accepted, revoked key rejected, rotated old key works during grace then expires, secret never appears in any response or log.
- **8.3** Ingest endpoint `POST /api/ingest/{source_uuid}` — HMAC signature verification, timestamp replay window, IP allowlist, rate limit, payload size cap, raw capture, `202` response under 200 ms. Test: valid request accepted; bad signature, stale timestamp, replayed body, wrong IP and oversized payload each rejected with the correct status; payload stored before processing.
- **8.4** Processing pipeline job — filter → map → transform → dedupe → persist → assign → trigger workflow → log. Test: each stage in isolation and end to end; duplicate delivery creates one record; failure rolls back cleanly.
- **8.5** Field mapping UI with listen mode, sample payload capture, auto-suggested mapping, clickable JSON paths, custom-field support, and dry-run test showing the would-be record. Test: mapping saves and round-trips; auto-suggestion matches sample; dry run creates nothing.
- **8.6** Transformation and dedupe rule builder — value maps, date parsing, name splitting, defaults, match-on rules with create/update/skip actions. Test: each transform type; each dedupe action produces the correct outcome.
- **8.7** **Reference implementation: project/task → Lead.** An external project system posts a created task or project; the CRM maps it to a Lead with source, external_id, owner assignment and a workflow trigger. Test: full end-to-end — POST creates the lead with correct field values; re-posting the same task updates rather than duplicates; the assigned rep is notified.
- **8.8** Pull-mode sync — endpoint config, auth, pagination, incremental cursor, cron schedule, "Sync now", per-run summary. Test: paginated fetch imports all pages; cursor advances; a second run imports only new records.
- **8.9** Event log viewer — filterable table (all four view modes), status chips, payload and mapped-output inspector, single and bulk replay, health dashboard per source, failure alerts to admin. Test: log filters, replay recreates correctly after a mapping fix, alert fires after N consecutive failures.

### Phase 9 — Customer Support
- **9.1** Tickets and cases — status, priority, assignment, linked contact/account. Test: CRUD, all 4 views + Kanban by status.
- **9.2** **Ticket notifications** — fire on ticket created, status changed, priority changed, assigned/reassigned, comment/reply added, resolved and closed. Recipients: the customer (contact), the assigned agent, the team admin, and any watchers. Channels: in-app + email + SMS, each toggleable per event per recipient type in Settings, with editable templates. Test: creating a ticket notifies customer, agent and admin on every enabled channel; a status change sends the status-change template with old and new status; disabling a channel in the matrix suppresses only that channel; a user-level mute overrides the global default; no notification is sent to the actor who made the change; all sends are queued; failures land in the notification log.
- **9.3** SLA policies with breach warnings and escalation, including SLA-breach notifications to agent and admin. Test: SLA clock, pause on hold, breach fires escalation and notification.
- **9.4** Knowledge base with categories and search. Test: search relevance, publish/unpublish.
- **9.5** Support analytics — volume, resolution time, agent performance, first-response time. Test: metric calculations against fixtures.

### Phase 10 — Reports & Analytics
- **10.1** Reporting engine — data sources, joins, groupings, aggregates. Test: aggregate correctness against known fixtures.
- **10.2** Custom report builder UI with drag-drop fields. Test: builder produces correct SQL results.
- **10.3** Chart library integration (bar, line, pie, funnel, gauge) with loading skeletons. Test: charts render with data and empty.
- **10.4** Standard reports — sales, leads, pipeline, conversion, revenue, salesperson, activity, customer. Test: each returns correct rows for seeded data.
- **10.5** KPI dashboards, configurable widgets, drag-to-arrange. Test: layout saves per user.
- **10.6** Forecasting (pipeline-weighted and historical). Test: forecast math against fixed datasets.
- **10.7** Scheduled reports emailed as PDF/Excel. Test: schedule dispatches, attachment generated.

### Phase 11 — QA, Security & Deployment
- **11.1** Full regression suite run and gap-fill. Test: entire suite green, coverage target met.
- **11.2** Security hardening — OWASP pass, rate limiting, mass-assignment audit, file upload validation, access-level bypass fuzz test (attempt to read another user's records by ID). Test: automated security tests pass.
- **11.3** Performance — N+1 elimination, indexes, cache layer, queue tuning, load test at target concurrency. Test: no N+1 detected, p95 under target.
- **11.4** Production infrastructure, CI/CD pipeline, backups, monitoring, error tracking. Test: pipeline runs the full gate on push.
- **11.5** Seed data, first-run installation wizard (company profile, admin account, mail provider), and demo data seeder. Test: a clean install completes the wizard and lands on a working dashboard.
- **11.6** User documentation and admin guide. Test: documented steps verified against the running app.

---

## START HERE

Begin with **Task 0.1**. Announce the task, list the files you will create, build it, write the tests, run the gate, commit, report, and stop for approval.
