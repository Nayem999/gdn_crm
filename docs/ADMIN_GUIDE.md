# Administrator Guide

Installing, configuring and running the CRM. Day-to-day use is in the
[User Guide](USER_GUIDE.md).

This is a **single-organisation** application: one company, one installation,
one database. There is no tenancy, and nothing here creates a second
organisation.

---

## 1. Requirements

| Component | Requirement |
| --- | --- |
| PHP | 8.2 or newer, with the usual Laravel extensions plus `intl` and `zip` |
| Database | MySQL 8.0+ (InnoDB, `utf8mb4_unicode_ci`) |
| Cache / queue | Redis |
| Web server | Anything that serves Laravel — nginx, Apache, Caddy |
| Node | 20+, to build the front-end assets |

---

## 2. Installing

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env
php artisan key:generate
```

Set at least `APP_URL`, the `DB_*` block and the `REDIS_*` block in `.env`, then
create the schema:

```bash
php artisan migrate --force
```

Do **not** seed anything by hand. Open `/install` in a browser instead.

### The first-run wizard

`/install` is open only while the installation has no user account. It has three
steps:

1. **Company** — name, timezone, currency and the month your fiscal year starts.
   The timezone is the clock your office reads; every value is stored in UTC and
   displayed in this zone.
2. **Administrator** — the first account. It owns the installation and holds
   every permission, and it is the account that invites everyone else.
3. **Email** — which provider outbound mail goes through. Leave it on the log
   driver to finish now and configure email later.

Finishing the wizard seeds the permission catalogue and the Super Admin role, a
default deals pipeline, the lead scoring rules and the standard reports; creates
the administrator; stores the mail settings; signs you in; and lands you on the
dashboard.

From that moment `/install` redirects to the sign-in page and stays shut, for
good. It is shut by the presence of *any* user account as well as by the marker
the wizard writes, so deleting every user does not reopen it.

### Demo data

To fill a demonstration installation with invented customers, deals, activities
and tickets:

```bash
php artisan db:seed --class=DemoDataSeeder
```

It creates a Sales team, a *Sales Manager* and a *Sales Representative* role
with real access levels, and three demo users — `priya@example.com`,
`tom@example.com` and `mei@example.com` — all with the password
`demo-password`.

Two things it refuses to do: run against an installation that already has
accounts in it, and run in production unless `DEMO_DATA_ALLOWED=true` is set.
Never point it at a database holding real customers.

---

## 3. Company profile

`/settings/company` — the name, logo and address that appear on quotes,
invoices and other branded documents, plus the timezone, currency and fiscal
year you chose during installation.

Changing the timezone moves every stored time's *apparent* hour by the offset.
That is inherent to having a display timezone; do it deliberately, not casually.

---

## 4. People and permissions

### Users

`/settings/users` — the accounts on the installation.

- **Invite user** (`/settings/users/invite`) emails a one-time link; the person
  sets their own password. Preferred over creating an account with a password
  you then have to transmit.
- **Create user** (`/settings/users/create`) is for when email is not yet
  working.
- Removing a user is a soft delete: their records keep their owner, and their
  history survives.

### Teams

`/settings/teams` — teams exist for one reason: they are what the *team* data
access level means.

Teams can be nested. Change membership through the team screen, never in the
database: adding or removing somebody also maintains the active team each user
is scoped by, and editing the pivot directly leaves people seeing records they
should not.

### Roles and access levels

`/settings/roles` — a role is a set of permissions **plus** a data access level.

| Access level | What the role's holders see |
| --- | --- |
| **Own** | Only records they own. |
| **Team** | Records owned by anyone in their active team, and its sub-teams. |
| **All** | Everything. |

Permissions say *what* somebody may do (`leads.convert`, `deals.close`,
`settings.secrets`); the access level says *which records* they may do it to.
Both apply — a role with `accounts.view` and *own* access can view accounts, but
only its own.

**Super Admin** is protected: it cannot be edited or deleted, and it
automatically holds every permission, including any added by a future upgrade.
Grant access by assigning a role, never by granting a permission directly to a
person — a directly granted account stops at whatever the catalogue held on the
day it was made.

A role still assigned to somebody cannot be deleted.

---

## 5. Settings

Each group of settings has its own page, reached from the settings sidebar.

| Page | What it holds |
| --- | --- |
| `/settings/localisation` | Date and time formats, the first day of the week, number and currency formatting. |
| `/settings/sales` | Quote validity, numbering, default tax mode and discount limits. |
| `/settings/scheduling` | Reminder lead times and working hours. |
| `/settings/notifications` | Quiet hours and per-channel limits. |
| `/settings/mail` | The outbound email provider and its credentials, the from-address, and the fallback provider. |
| `/settings/meta` | The Meta app this CRM talks to — app ID, app secret, webhook verify token and dataset ID. The application's own credentials; the pages, ad accounts and WhatsApp numbers themselves are connected under Marketing & Social. |
| `/settings/inbound` | The mailbox polled for replies, so a customer's answer lands on the record. |
| `/settings/api` | API defaults — rate limits and page sizes. |
| `/settings/integrations` | Shared integration settings. |
| `/settings/sms`, `/settings/whatsapp` | Messaging providers. |
| `/settings/storage` | Where uploaded documents are kept. |

### How credentials are handled

- Every secret is encrypted at rest and is **write-only** in the UI: a stored
  secret is shown as dots and never sent to the browser. Leaving a secret blank
  when you save means "keep what is stored".
- Reading or replacing secrets is a separate permission, `settings.secrets`.
  Without it the fields are not rendered at all, and a submitted secret is
  dropped server-side.
- The audit trail records *that* a secret changed and which key it was — never
  the value, the old value, or its length.
- Credentials are configured here, not in `.env`. The environment file holds the
  database, Redis and app key; the application's own integrations do not belong
  in it.

### Email

`/settings/mail` supports SMTP, Mailgun, Brevo, SendGrid, Amazon SES, Postmark,
and a log driver that writes messages to the log instead of sending them.

- **Test connection** proves the credentials against what is on the form,
  including changes you have not saved yet, without sending anything.
- **Send a sample** sends one real message to an address you choose.
- A **fallback** provider is tried when the first refuses a message outright.
- `/settings/email-delivery` is the delivery log: what was sent, what bounced,
  what was opened.
- `/settings/email-templates` holds the templates used for outbound mail.

---

## 6. Shaping the CRM

| Page | What you configure |
| --- | --- |
| `/settings/custom-fields` | Extra fields on any module — text, number, date, select, checkbox. They are data, not columns: adding one does not change the schema, and they are filterable, exportable and importable like any other field. |
| `/settings/custom-modules` | Whole new record types, which appear in the sidebar and get the same list, filter and export behaviour as the built-in ones. |
| `/settings/pipelines` | Deal pipelines and their stages: name, colour, probability and whether the stage counts as won, lost or open. Several pipelines can run at once; one is the default. |
| `/settings/price-books` | Customer-segment pricing, including quantity price breaks. |
| `/settings/lead-scoring` | The rules that produce a lead's score, and the qualification requirements a lead must meet before it can be converted. No requirements are set by default, so a fresh installation never blocks the pipeline. |
| `/settings/lead-forms` | Public lead capture forms. Each gets an unguessable URL that can be embedded in an iframe on your website; submissions are rate-limited, honeypotted and timed. |
| `/settings/sla-policies` | Response and resolution targets per priority, and who is warned as a breach approaches. |

---

## 7. Automation and approvals

`/workflows` — a workflow is a trigger, a condition tree and a list of steps.

- **Triggers**: a record created, updated or moved to a stage; a date arriving;
  or a schedule.
- **Steps**: create an activity, send a notification or an email, update a
  field, call a webhook, or request an approval.
- `/workflows/log` shows every run, its steps and why one stopped.
- Approval steps appear at `/approvals` for the named approver. Approvals nobody
  answers are escalated on the schedule.

Date-based and scheduled triggers depend on the scheduler running (§10).

---

## 8. Integrations

| Page | Purpose |
| --- | --- |
| `/settings/api-keys` | Personal API tokens, with abilities — a read-only key is genuinely read-only. Shown once when created. |
| `/settings/api-documentation` | The generated OpenAPI description of the REST API, also served at `/api/v1/openapi.json`. |
| `/settings/webhooks` | Outbound webhooks: pick the events, give an endpoint, get a signing secret. Deliveries are retried with a backoff and every attempt is logged. |
| `/settings/data-sources` | Inbound sources, both push (they post to `/api/ingest/{source}`) and pull (the CRM fetches on a schedule). |
| `/settings/delivery-log` | Every inbound event: what arrived, what it became, and what to replay. |

Inbound data is captured raw and processed on the queue, validated against the
mapping you define at `/settings/data-sources`. No payload field can name a
column or a class.

Regenerate the API description after changing the API:

```bash
php artisan api:docs
```

---

## 8a. Connecting Meta

Facebook Pages, Lead Ads, advertising figures, Messenger and WhatsApp. Two
screens: `/settings/meta` holds the **application's** credentials, and
`/settings/meta/connect` holds what those credentials have been used to
connect.

### 1. The app credentials

`/settings/meta` takes the app ID, the app secret, a webhook verify token of
your own choosing and — only if you report conversions back — a dataset ID.
They are encrypted at rest and never written to the environment file.

### 2. The connection

`/settings/meta/connect` offers two ways in:

- **Connect Meta** sends you to Meta to sign in. Meta only returns to a public
  HTTPS address, so this is unavailable on a laptop or a company network.
- **Connect with an access token** takes a system user token from Business
  Manager instead, with the ids it was given: the WhatsApp business account,
  the Facebook Page, the ad account.

Meta issues a token **per asset** and they are often different strings, so each
id has its own optional token beside it. Leave one blank and the token at the
top is used for it. Each id is checked against Meta as it is stored and
reported on separately — one wrong id does not discard the others.

**Test connection** checks each capability independently: authentication, the
permissions Meta actually granted, the Page, the ad account, the WhatsApp
number and the webhook token. A missing permission is named.

### 3. Where replies arrive

The same screen prints the three webhook addresses and the verify token to
paste into Meta, with the field to subscribe beside each:

| Product | Subscribe | Address |
| --- | --- | --- |
| Facebook Lead Ads | `leadgen` | `/api/webhooks/meta/leadgen` |
| Facebook Messenger | `messages` | `/api/webhooks/meta/messenger` |
| WhatsApp | `messages` | `/api/webhooks/meta/whatsapp` |

The addresses are built from the application's configured URL. **Meta cannot
deliver to a private address**, so nothing arrives until the CRM is published
somewhere Meta can call — the screen says so rather than leaving you to find
out. Sending, templates and the advertising figures work regardless; only
inbound messages need it.

One callback exists per product per app. Pointing it at this CRM takes delivery
away from whatever held it before.

### 4. What is read, and when

| Command | What it does |
| --- | --- |
| `php artisan meta:sync-ads` | Campaigns, ad sets, advertisements and their daily figures. Scheduled. |
| `php artisan meta:backfill-leads` | Lead Ads submissions that arrived before the CRM was connected. |
| `php artisan meta:import-messenger` | A Page's existing Messenger conversations. Creates no leads unless you pass `--create-leads=`. |

There is no WhatsApp equivalent of the last one. The Cloud API publishes no
endpoint for message history, so a WhatsApp thread begins at the first message
delivered to your webhook and earlier conversations cannot be fetched.

### What it produces

`/settings/meta/campaigns` links a Meta campaign to a CRM campaign and shows
spend, impressions, reach, clicks, CTR and CPC. `/settings/meta/performance`
carries that through to leads, deals and revenue with cost per lead and return.
`/settings/meta/conversions` shows what has been reported back to Meta, and
retries what failed.

Meta's spend is never written into a CRM campaign's own cost: that column is a
figure a person typed, and an integration that overwrote it would be one nobody
could correct.

---

## 9. The audit trail

`/settings/audit-log` — who changed what, and when: field-level changes, logins,
role changes, settings changes, deletions and restores. It is filterable by
person, module and date, and it is not editable from anywhere.

---

## 10. Running it in production

### Queue workers

Imports, exports over a thousand rows, emails, webhooks and report generation
all run on the queue. Nothing works properly without a worker.

```bash
php artisan horizon
```

Run it under a process supervisor (systemd or supervisord) so it restarts. The
dashboard is at `/horizon`, and only users who may administer the application
can open it.

### The scheduler

One cron entry runs everything else:

```bash
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

It drives:

| Command | When | What it does |
| --- | --- | --- |
| `php artisan activities:send-reminders` | every minute | Queues reminders whose lead time has arrived. |
| `php artisan workflows:run-triggers` | every minute | Fires date-based and scheduled workflows, and escalates unanswered approvals. |
| `php artisan support:sweep-sla` | every minute | Warns on and records SLA breaches. |
| `php artisan mail:sync-inbound` | every 5 minutes | Reads replies from the inbound mailbox. |
| `php artisan ingest:sync` | every 15 minutes | Fetches from the inbound sources that pull. |
| `php artisan reports:send-scheduled` | hourly | Queues the scheduled reports that have come due. |
| `php artisan quotes:expire` | 00:10 | Lapses quotes past their validity date. |
| `php artisan activities:generate-recurrences` | 01:15 | Rolls the recurring-activity window forward. |
| `php artisan backup:database` | 02:30 | Dumps the database and prunes old dumps. |

### Backups

```bash
php artisan backup:database
```

Dumps to the **private** disk — nothing serves it — and prunes dumps older than
the retention window. It is scheduled nightly, and a failure is logged rather
than passed over silently. Copy the dumps off the machine: a backup on the same
disk as the database is not a backup. Uploaded documents live on the private
disk too and need copying as well.

### Health and errors

- `/up` is the health endpoint a monitor should poll. It answers only when the
  database and the cache both answer.
- Every logged exception carries the user id, the URL, the method and the IP, so
  "who saw this?" is answerable. An external error tracker plugs into
  `bootstrap/app.php`.

### Caching after a deploy

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan horizon:terminate
```

`horizon:terminate` is what makes workers pick up new code. If a front-end
change does not appear, the assets were not rebuilt: `npm run build`.

---

## 11. Upgrading

1. Put the site into maintenance mode, or accept a brief inconsistency.
2. Pull the new code, then `composer install --no-dev --optimize-autoloader` and
   `npm ci && npm run build`.
3. `php artisan migrate --force`.
4. `php artisan db:seed --class=BaselineSeeder --force` — idempotent, and how
   permissions and standard reports added by the new version reach the Super
   Admin role.
5. Re-cache as above and `php artisan horizon:terminate`.

Take a backup before step 3, every time.

---

## 12. Security notes

- Sessions are bound to the current password hash, so changing a password signs
  out the other devices.
- The public capture form, the chat widget and the provider webhooks are the
  only unauthenticated write paths. Each is protected by an unguessable token, a
  rate limit, and a payload that can do exactly one thing.
- Document bytes are on the private disk and are only ever streamed through
  `/documents/{document}/download`, which asks the policy first.
- Record visibility is enforced in the query, not in the view, so an API call
  and a screen answer the same question the same way.
