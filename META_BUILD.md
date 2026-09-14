# Meta Build Prompt — Facebook, Instagram & WhatsApp for GDN CRM

> Phase 12 of this application. Read `CRM_BUILD.md` first: the working agreement,
> the stack, the architecture rules and the UI standard all still apply, and
> nothing here overrides them.

---

## 1. What is already here

This was written after reading the code, not from the brief. Every reuse below
is a decision to **not** build something the brief asks for, because the
application already does it — and doing it twice is how a CRM ends up with two
inboxes, two webhook pipelines and two sets of integration logs.

| The brief asks for | What exists | What we do |
| --- | --- | --- |
| §25 Webhook system, signature verification, idempotency, queue processing, retry | `app/Domain/Ingestion` — `IngestGuard`, `IngestSignature` (HMAC), `CaptureIngestEventAction` (capture raw, respond fast), `ProcessIntegrationEventAction`, `ReplayIntegrationEventAction`, `IntegrationEvent` with status + replay, per-source rate limiting, IP ranges | **Reuse.** Meta becomes an inbound source kind, not a second gateway. Meta's `X-Hub-Signature-256` and the `hub.challenge` handshake are a new signature strategy inside `IngestSignature`, not a new controller pattern. |
| §26 Integration logs, filters, retry button | `/settings/delivery-log` (`IntegrationLog`), `IntegrationHealth`, `IntegrationEventExportSource` | **Reuse.** Add Meta providers to the existing filters. |
| §7 Duplicate management, Update / Create / Skip | `DedupeAction` enum (Update, Skip, Create), `app/Domain/Shared/Duplicates`, `LeadDuplicates`, `MergeRecordsAction`, `WarnsAboutDuplicates` | **Reuse.** "Meta Lead Duplicate Policy" is a per-source `DedupeAction` plus a Meta-lead-id uniqueness gate in front of it. |
| §8 Field mapping UI, test mapping | `DataSourceMapping`, `PayloadMapper`, `PayloadReader`, `MappingSuggester`, `DryRunMappingAction`, `/settings/data-sources/{source}/mapping` | **Reuse.** A Facebook form's `field_data` is a payload like any other; the mapping screen already tests against a sample. |
| §9 Assignment rules — user, team, territory, round robin, load | `app/Domain/Workflows/Assignment` — `AssignmentResolver` with Fixed, RecordOwner, RoundRobin, LoadBased, Territory; workflows have a condition tree and a `record_created` trigger | **Reuse.** A Meta assignment rule is a workflow on Lead created where source is a Meta source. No parallel rule engine. |
| §14–19 WhatsApp Cloud API | `app/Domain/Messaging/Providers/CloudApiProvider` — phone number id, encrypted token, Graph version, `verify()`, `send()`, and it already refuses outside the 24-hour window | **Extend.** It can send; it cannot receive, thread, template or attribute. That is the work. |
| §5 Facebook inbox, §15 WhatsApp inbox | `app/Domain/Chat` — `ChatConversation`, `ChatMessage`, `RecordChatMessageAction` (unknown visitor → lead when there is something to reach) | **Generalise.** One conversation model with a channel, not three. §16's flow is `RecordChatMessageAction`'s flow with a phone number instead of a session id. |
| §24 Unified timeline | `TimelineRegistry`, `TimelineBuilder`, `CommunicationGatherer`, `CommunicationChannel` — which **already has a WhatsApp case** | **Reuse.** Add a gatherer source; nothing else. |
| §21 Conversion events | `app/Domain/Webhooks` (outbound, signed, retried, logged), `DeliverWebhook` job | **Pattern only.** The Conversions API is its own client, but the delivery/retry/log shape is copied from here. |
| §30 RBAC | `PermissionCatalogue` + `SyncPermissionCatalogueAction`; policies on every model | **Reuse.** New groups added to the catalogue, seeder re-run. |
| §29 Encrypted credentials | `SettingsRegistry` + `SaveSettingsAction` — encrypted at rest, write-only in the UI, redacted in the audit trail, `settings.secrets` permission | **Reuse.** No Meta token is ever stored outside this. |
| §35 Reports, §36 dashboard widgets | `ReportSources`, report builder, KPI dashboard, `DashboardWidget` | **Reuse.** New report sources, not a new reporting engine. |
| §38 API | `app/Domain/Api` — `ApiModules`, `ResourceController`, OpenAPI generation, Sanctum abilities | **Reuse.** New API modules register themselves. |

### Three things the brief assumes that are not true here

1. **There is no Campaigns module.** Nothing in `app/Domain` is a campaign, and
   no table is. §11, §12, §22, §35 and §36 all depend on one. It has to be built
   first, as a CRM module in its own right — not as a Meta side-table, or every
   non-Meta campaign the company runs is homeless. This is task 12.2.
2. **Deals are not an ingestion target.** `IngestionTargets` allows leads,
   contacts and accounts only, because a target needs an `ImportSource`
   declaration. Nothing in this phase writes deals from outside, and §22's deal
   linkage is through the lead's conversion, which already carries
   `converted_deal_id`.
3. **`leads` has no attribution columns.** It has `source` (a `LeadSource` enum)
   and nothing else. Attribution is task 12.3, and it is real columns rather
   than custom fields: §13 says "do not lose attribution when a lead is
   converted", and conversion has to copy them explicitly.

### What Meta will not let us do, whatever we write

Stated here so it is not discovered at acceptance:

- **App Review gates everything live.** `leads_retrieval`, `pages_messaging`,
  `pages_manage_metadata`, `ads_read`, `whatsapp_business_messaging` and
  `business_management` are all reviewed permissions. Until the app is approved
  and the business verified, only users with a role on the app can authorise it.
  Every task below is therefore testable against recorded responses
  (`Http::fake`) and a sandbox, and the acceptance criteria that need live data
  are marked.
- **The 24-hour window is Meta's, not ours.** Outside it only an approved
  template may be sent. `CloudApiProvider` already refuses rather than pretending.
  The inbox will show the window's state and disable free-form reply when it has
  closed — §18's "do not bypass template approval" implemented as a visible rule.
- **Messenger has its own window** (7 days, plus message tags). Same treatment.
- **Instagram is out of scope** by decision (see 12.9). It is in the brief's final diagram but nowhere in its 45 sections.
  It rides the same Messenger webhook and the same conversation model, so it is
  one extra channel case whenever it is wanted — but it needs
  `instagram_manage_messages` and an Instagram account linked to the page.
- **The Conversions API needs a dataset (pixel) id**, and lead-ads conversions
  are sent as `Lead`/`Purchase` events keyed by the Meta lead id — not by our
  record ids. Deduplication is on `event_id`, which we generate and store.
- **Ad insights are not real-time.** Meta's own attribution windows mean spend
  figures move for up to 72 hours. Synced figures carry the time they were read,
  and the dashboard says so rather than implying a live number.

---

## 2. Architecture

```
app/Domain/Meta/                 The Meta account, pages, ad accounts, OAuth, Graph client
    Graph/                       MetaGraphClient, MetaApiVersion, rate limiting, error mapping
    Actions/                     Connect, Disconnect, Sync*, TestConnection
    Models/                      MetaAccount, MetaPage, MetaAdAccount, MetaForm
    Enums/                       MetaChannel, MetaConnectionStatus, MetaSyncState
    Policies/
app/Domain/Meta/Ads/             Campaigns, ad sets, ads, insights
app/Domain/Meta/Leads/           Lead-ad retrieval, mapping, the create/update pipeline
app/Domain/Meta/Conversions/     Conversions API client, event dedupe, delivery log
app/Domain/Social/               One conversation model for Messenger, Instagram and WhatsApp
    Models/                      SocialConversation, SocialMessage, SocialParticipant
    Actions/                     RecordInboundMessage, SendMessage, AssignConversation
    Enums/                       SocialChannel, MessageDirection, MessageType, MessageStatus
app/Domain/Campaigns/            The CRM campaign module (task 12.2)
app/Livewire/Meta/               Settings, wizard, pages, ads, campaigns, analytics
app/Livewire/Social/             The shared inbox (channel is a filter, not a second screen)
```

**Rules for this phase, on top of the ones in `CRM_BUILD.md`:**

- No Graph call outside `MetaGraphClient`. It owns the version, the retries, the
  rate-limit backoff and the mapping of Meta's error codes to our own.
- The Graph API version is configuration (`config/meta.php`, `META_GRAPH_VERSION`),
  never a literal in a service. One place to bump, one place to test.
- No token reaches a Livewire component, a view, a log line, an API response or
  an exception message. Tokens live in `settings` (encrypted) and are read by the
  client at call time.
- Every webhook returns inside its time budget. Meta retries on anything slow,
  and a retried lead-ad event that we processed synchronously is a duplicate lead.
- Everything Meta sends is untrusted, including the ids. A payload never names a
  column, a class or an owner.

---

## 3. Database

New tables. Every one InnoDB, `utf8mb4_unicode_ci`, explicit `onDelete`, money
`DECIMAL(15,2)`, and an index on everything the filter builder exposes.

| Table | Holds | Key indexes |
| --- | --- | --- |
| `campaigns` | The CRM campaign (12.2): name, type, status, owner, dates, budget, expected revenue, actual cost | `(status, start_date)`, `owner_id` |
| `meta_accounts` | One connected Meta business: business id, name, status, connected_by, connected_at, token metadata (never the token), scopes granted, last_synced_at | unique `business_id` |
| `meta_pages` | Page id, name, category, connected, subscribed_fields, last_synced_at, `meta_account_id` | unique `page_id` |
| `meta_ad_accounts` | Ad account id, name, currency, timezone, status | unique `ad_account_id` |
| `meta_forms` | Lead form id, name, page, status, questions (JSON), last_lead_at | unique `form_id` |
| `meta_campaigns` | Meta campaign id, name, objective, status, dates, budget, `campaign_id` (our campaign, nullable) | unique `meta_campaign_id`, `campaign_id` |
| `meta_ad_sets` | Ad set id, name, `meta_campaign_id`, budget, status, optimisation goal, dates | unique `ad_set_id` |
| `meta_ads` | Ad id, name, `meta_ad_set_id`, status, creative summary | unique `ad_id` |
| `meta_insights` | One row per entity per day: level (campaign/adset/ad), entity id, date, spend, impressions, reach, clicks, ctr, cpc, cpm, leads, conversions, currency, read_at | unique `(level, entity_id, date)` |
| `meta_leads` | The raw lead-ad record: `meta_lead_id` (unique), form, page, campaign/adset/ad ids, raw payload, `lead_id`, status, error, received_at, processed_at | unique `meta_lead_id`, `lead_id` |
| `meta_conversions` | event_id (unique), event_name, lead/contact/deal id, value, currency, event_time, status, http status, response, attempts | unique `event_id` |
| `social_conversations` | channel, external_conversation_id, page/phone number id, participant name + handle + external id, `lead_id`, `contact_id`, `assigned_to_id`, status, unread_count, last_message_at, window_expires_at | unique `(channel, external_conversation_id)`, `(assigned_to_id, status)`, `lead_id`, `contact_id` |
| `social_messages` | conversation, external_message_id (unique per channel), direction, type, body, media (JSON), template name, status, sent/delivered/read_at, sender user id, error | unique `(channel, external_message_id)`, `(conversation_id, created_at)` |
| `whatsapp_templates` | name, language, category, status, body, variables (JSON), synced_at | unique `(name, language)` |

Extensions to existing tables:

- `leads`, `contacts` and `deals` each gain **`campaign_id`** (12.2) — the CRM-level
  link the list screens filter by and a person edits.
- The attribution *detail* lives in **`marketing_attributions`**, one row per
  record, addressed polymorphically (12.3): source and source detail, the Meta
  lead / page / form / campaign / ad set / ad ids and names, the click id, the
  five UTM fields and the moment of first touch.

  One table rather than the sixteen columns per module the brief implies. Three
  copies would be forty-eight mostly-null columns across the three busiest tables
  in the application, three places to write them, three to read them, and three
  chances for conversion to forget one. Every field is a real column — attribution
  is precisely what has to be filtered, grouped and joined, which a JSON blob
  cannot do.
- `LeadSource` gains `FacebookLeadAds`, `FacebookMessenger` and `WhatsApp`.
  Additive: a case removed or renamed would orphan every row holding it.
- `LeadSource`: new cases `FacebookLeadAds`, `FacebookMessenger`, `WhatsApp`,
  `InstagramDirect`. Existing rows are untouched — the enum is additive.

---

## 4. The task plan

Same contract as `CRM_BUILD.md`: one task, built, tested, gated, committed,
reported, then stop. Every task ships its own tests.

### 12.1 — Foundations: config, credentials, permissions, the Graph client
`config/meta.php` with the packaged Graph version and the HTTP behaviour — and
no credentials at all. The `meta` settings group holds the app id, app secret,
webhook verify token and dataset id, every secret encrypted through
`SaveSettingsAction` (see §6). `MetaGraphClient` with version pinning,
`appsecret_proof`, retry only where a retry could help, rate-limit awareness, and
Meta error codes mapped to our own. Permission groups `meta`, `social` and
`whatsapp` added to `PermissionCatalogue`.
**Test:** the client calls the configured version and falls back rather than
building a broken URL; a token never reaches a URL, a log or an exception; a
refusal is classified and not retried; a stored secret never reaches the screen
and cannot be written without `settings.secrets`; no Meta credential is read from
the environment or named in `.env.example`; every new permission is in the
catalogue and on the Super Admin role.

### 12.2 — The CRM Campaigns module
Model, policy, factory, list screen with the full UI standard, form, show page
with related lists, timeline, custom fields, import/export, report source.
Campaign is where Meta spend later meets CRM revenue.
**Test:** the module satisfies the UI standard sweep; access levels scope it; a
campaign links to leads, contacts and deals.

### 12.3 — Attribution on the record
The `leads`/`contacts`/`deals` columns above, the new `LeadSource` cases, a
`MarketingAttribution` value object, the **Marketing Attribution** panel on the
lead detail page (§23), and carry-forward through `ConvertLeadAction` (§37).
**Test:** converting a lead preserves every attribution field on the contact,
account and deal; the panel renders; attribution survives a merge.

### 12.4 — Connect Meta: OAuth, the wizard, test connection
`MetaAuthService` (OAuth redirect, code exchange, long-lived token exchange,
token metadata and expiry), the 11-step connection wizard (§32), page / ad
account / WhatsApp account selection, disconnect and reconnect, and the test
tool (§33) reporting SUCCESS/FAILED per capability.
**Test:** the OAuth callback rejects a mismatched `state`; a failed exchange
leaves nothing connected; disconnect revokes and clears; the wizard cannot be
completed out of order; the test tool reports each capability independently.

### 12.5 — The Meta webhook gateway
`/api/webhooks/meta/{channel}` on the ingestion pipeline: `hub.challenge`
verification, `X-Hub-Signature-256` validation against the app secret, capture
raw and respond, process on the queue, idempotency on Meta's event id, replay
from the delivery log.
**Test:** a bad signature is refused and logged; the challenge handshake answers
correctly; the same event twice produces one record; a handler exception leaves a
retryable event, not a lost one; the endpoint answers within its budget.

### 12.6 — Facebook Lead Ads → CRM leads
`MetaLeadService`: retrieve by lead id, map through the source's field mapping,
dedupe on `meta_lead_id` then phone then email under the source's `DedupeAction`,
create or update, attribute, assign (workflow), activity, notify (§6).
Backfill job for leads that arrived while the webhook was down.
**Test:** a form submission becomes a lead with full attribution; the same
`meta_lead_id` twice creates one lead; each duplicate policy behaves as
configured; a mapping that cannot satisfy a required field fails the event
visibly rather than writing a half lead; the owner comes from the rule, not the
payload.

### 12.7 — Ads synchronisation and insights
Ad accounts, campaigns, ad sets, ads and daily insights, chunked and queued,
scheduled every 15 minutes with per-entity cursors. Meta campaign ↔ CRM campaign
linking (§11).
**Test:** a sync is idempotent; a partial failure does not lose the cursor; rate
limiting backs off rather than hammering; insights upsert per day rather than
duplicating; linking is one-to-one and reversible.

### 12.8 — The unified social inbox: Messenger
`social_conversations`/`social_messages`, inbound message handling, the
three-column inbox (§5, §15), reply within the window, assignment, and the CRM
actions on the right-hand panel: create lead, contact, deal, note, task.
**Test:** an inbound message threads onto the right conversation; an unknown
sender with something to reach becomes a lead; reply outside the window is
refused with Meta's reason; the panel's actions carry attribution.

### 12.9 — Instagram Direct *(out of scope — decided)*
Not built. The conversation model is channel-agnostic, so adding it later is one
channel case and a permission, not a rewrite.

### 12.10 — WhatsApp: inbox, templates, sending
Receive on the Cloud API webhook, thread by phone number, unknown number → lead
(§16), template sync and status (§18), send from lead / contact / deal (§19),
media handling, delivery and read receipts, the 24-hour window shown honestly.
**Test:** a new number creates a lead and a conversation; a known number attaches
to the existing contact; an unapproved template cannot be sent; a template's
variables are validated before sending; receipts update the message; media is
stored on the private disk and streamed through a policy.

### 12.11 — WhatsApp lead ads (click-to-WhatsApp)
Referral payload on the first message carries the campaign, ad set and ad; the
lead created in 12.10 is attributed from it (§20).
**Test:** a referral payload attributes the lead; a message without one still
creates the lead, unattributed rather than wrongly attributed.

### 12.12 — Conversions API
Send CRM outcomes back to Meta (§21): qualified, opportunity, closed won, with
value and currency. Idempotent on a generated `event_id`, delivered on the queue,
logged with Meta's response, retryable from the log.
**Test:** a won deal sends one event; the same deal twice sends none; a failure
is retryable and never double-counts; nothing is sent for a lead with no Meta id.

### 12.13 — Analytics, reports and dashboard widgets
The campaign dashboard (§12), the three reports (§35), the twelve widgets (§36),
and the full attribution chain campaign → ad set → ad → lead → deal → revenue
with CPL, CPQL, CPA, conversion rate and ROI.
**Test:** every figure is computed from stored rows, not from a live call; the
access level scopes the numbers; the date filters agree with the report builder's.

### 12.14 — Navigation, logs, audit and the acceptance sweep
The **Marketing & Social** sidebar section with its ten entries, Meta providers
in the delivery log filters, audit entries for every action in §40, and a sweep
test over §43's 25 acceptance criteria.
**Test:** the sweep passes; the existing suite is untouched.

---

## 4a. Decisions taken

- **Campaigns**: the full CRM module (12.2), not a Meta-only table.
- **Instagram**: out of scope for this phase.
- **Credentials**: everything is built and tested against recorded Graph and
  Cloud API responses. No test touches the network. Live verification waits for
  an approved Meta app.
- **Cadence**: one task per turn, gated and committed, then stop for approval.
- **Permission naming**: the brief asks for `facebook.inbox.*` and
  `integration.logs.view`. The inbox is one screen for every channel, so it is
  `social.inbox.*`; and integration logs already exist behind `integrations.view`,
  which is reused rather than duplicated. WhatsApp keeps its own group because
  Meta's template and window rules make it a genuinely different permission.

## 5. Testing

Every Graph and Cloud API call is faked with recorded response shapes; no test
touches the network (`tests/Pest.php` already blocks DNS for webhook targets).
Fixtures live in `tests/Fixtures/Meta/`. Coverage matches §42: OAuth success,
failure and expiry; leads new, duplicate, existing phone, existing email, mapping
and assignment; WhatsApp new conversation, existing customer, inbound, outbound,
template, invalid template, duplicate; campaign, ad set, ad and insight sync;
webhooks valid, invalid signature, duplicate and retry; conversions won, sent and
deduplicated.

## 6. Secrets

**Every Meta credential is configured in Settings → Meta and stored encrypted in
the database. None of them goes in `.env`, and none of them is named in
`.env.example`.**

That is a decision, not an oversight. A credential in the environment file is a
credential in every deployment script, every server backup and every
`config:cache` artefact; it cannot be rotated without a deploy; and
`.env.example` is where one eventually gets committed by somebody filling it in
"just to test". Stored through `SaveSettingsAction` it is encrypted at rest,
write-only in the UI, redacted in the audit trail as `[secret changed]`, and
gated behind the separate `settings.secrets` permission — which is how every
other integration credential in this application is already handled.

So: the app ID, the app secret, the webhook verify token and the dataset ID live
in the `meta` settings group. Page tokens, ad account tokens and WhatsApp tokens
are per-connection and live encrypted alongside the connection record. `config/meta.php`
holds only what is not secret and does not identify the installation — the Graph
version this application ships against, timeouts, retry counts and the
rate-limit ceiling.

A test in `MetaFoundationsTest` fails the build if a Meta credential name
reappears in `.env.example` or is read through `env()`.
