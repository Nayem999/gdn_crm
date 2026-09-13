---
paths:
  - 'app/Domain/Ingestion/**'
  - 'app/Livewire/Settings/DataSources.php'
  - 'resources/views/livewire/settings/data-sources.blade.php'
  - 'database/migrations/*ingestion*.php'
---

# Inbound data gateway

Phase 8. An outside system putting records **into** the CRM, which is the
opposite direction from [[integrations]] — an API key lets somebody read what
they could already see, a data source lets somebody else's software create rows.
The permissions are therefore separate (`integrations.view` / `.manage`) rather
than folded into the `api` group.

## A source stores a module key, and `IngestionTargets` is the only place it becomes a class
"A source can only write into its configured target module" is not a check in
the pipeline — it is the fact that the pipeline resolves the **stored**
`target_module` through the registry, and nothing in a payload, a form field or
a URL can reach that column. A registry that resolved a class named in a request
is one typo away from arbitrary instantiation, which is the same rule
`ApiModules`, `ImportRegistry` and `TimelineRegistry` follow.

Both the create and the update action check the key themselves rather than
trusting the screen's `Rule::in`. One screen running the check is one screen; a
later importer or seeder would not.

## The target module is fixed once the source has written a record
Not once it has *received* one: somebody still setting an integration up sends
test payloads and has to be able to correct a mistake. The first written record
is what fixes it, because that is the moment the choice starts to mean
something — the event log now says this source makes leads, and 8.5's field
mappings will name lead columns. Re-aiming it breaks both, and neither fails
loudly. The refusal says to add a new source instead, which costs nothing and
gives the outside system a new address, honestly reflecting that it is a
different integration.

## Enabled and sandbox are two switches, not three states of one
- **Disabled** refuses the delivery. `DataSource::forIngest()` is the first and
  cheapest gate — the answer before a signature is verified or a body is read.
- **Sandbox** accepts and captures, and writes nothing. An integration being
  built needs to send real payloads and see what they would produce, and turning
  the source off would leave it with nothing to look at.

`acceptsDeliveries()` and `writesRecords()` are the two questions; keep them
apart. Task 8.3's endpoint asks the first, 8.4's pipeline asks the second.

`forIngest()` returns null for unknown, deleted and disabled alike. Saying which
it was tells a caller which uuids exist.

## The uuid is the address, and only the model mints it
A source's public identifier is a uuid, not its primary key: the URL is handed
to an outside system, and a sequential id in it says how many integrations there
are and invites a guess at the next one. It is minted in a `creating` hook
rather than by whoever creates the row, because the screen is not the only thing
that does, and a source without one has no ingest address at all. It is out of
`$fillable` — an outside system has already been given it.

`ingestUrl()` is built from the uuid rather than stored, so it cannot drift from
the route or survive the application moving host.

## An event is what happened, so it copies rather than joins
`integration_events` is the only account of what an outside system actually
sent, and it has to stay believable after the source has been reconfigured.
`is_sandbox` and `signature_verified` are therefore **copied onto the event**,
the way a stage visit copies its stage name (see [[deals]]). Somebody takes a
source out of sandbox the moment it works, and every event before that would
otherwise read as though it had written a record.

`payload` is `longText` holding the bytes that arrived, not a `json` column. The
signature is computed over those bytes, so a column that reserialised them makes
it unverifiable afterwards and makes a replay send something subtly different
from the original. It is stored before anything parses it, and it is **data** —
never evaluated, unserialised or executed, and rendered escaped.

`status`, `outcome`, `mapped_output`, the record pair, `attempts` and `error`
are out of `$fillable`: the processing pipeline owns them, the way the complete
and cancel actions own an activity's status. Nothing that captures a delivery
may declare what became of it — there is a test that tries.

`received_at` is `dateTime()`, not `timestamp()`, or processing a delivery would
silently rewrite when it arrived. See [[migrations]].

## Two credentials, because HMAC and hashing want opposite things
The brief asks for secrets **hashed at rest and compared in constant time**, and
also for **HMAC-SHA256 signature verification**. Those cannot be the same stored
value: verifying an HMAC means recomputing it, which needs the key itself, and a
hash cannot be recomputed from. So a source gets two:

- **The key** (`X-CRM-Key`) is *presented*, so it is stored as a SHA-256 hash
  and compared with `hash_equals`. Never recoverable.
- **The signing secret** is an HMAC key, so it is stored encrypted (`Crypt`,
  the standard this project already sets for integration credentials). Not a
  weaker choice made for convenience — a hash simply cannot do the job.

SHA-256 rather than bcrypt for the key: it is 48 characters of CSPRNG output, so
there is nothing to brute-force and no need for a work factor, and it is checked
on **every delivery**, where a deliberate work factor is a denial-of-service
lever pointed at ourselves. Sanctum reasons the same way about API tokens.

Both carry the `crmk_` / `crms_` prefixes so a leaked credential is recognisable
to a secret scanner rather than only to whoever went looking for one.

## Issue and rotate are one action, revoke is another
`IssueSourceSecretAction` covers first issue and rotation, because they are the
same act — the only difference is whether there was something to keep working.
Two actions would be two places deciding how a credential is stored.

The grace window is written as an **expiry stamp**, not a duration, so changing
the setting afterwards cannot silently extend a rotation that already happened,
and the old key stops on the clock rather than when a sweep somebody has to
remember to run gets around to it. Only **one** key back is kept: rotating twice
in a day retires the oldest rather than leaving three alive.

A grace of zero drops the old key at once, which is what somebody rotating
*because it leaked* wants. **Revoke has no grace at all**, for the same reason —
"it keeps working for another day" is the opposite of what that situation needs
— and it takes the grace copy with it, or it would revoke nothing an attacker
was actually using. Revoking leaves the source switched on: it says "nobody may
authenticate as this" and decides nothing else.

A null stored hash is a **miss**, never a match. Otherwise "no key issued" would
mean "any key works".

## The secret is shown once, and nothing can show it again
`SourceCredentials` is the only time either value exists outside the caller's
system. It is held in component state until the administrator dismisses it or
the page reloads, and it is never read back from the database — the key cannot
be, and the signing secret deliberately is not, because a screen that offers to
show a credential again is a screen that decrypts one on demand.

Both columns are in `DataSource::$hidden`, so neither reaches an API response, a
queued job payload or a `dd()`. `SourceCredentials` overrides `__debugInfo()`
and `__toString()` to redact itself in a stack trace.

`integrations.secrets` is its own permission, for the reason `settings.secrets`
is separate from `settings.update`: somebody can be trusted to switch a
misbehaving source off without being handed the ability to mint a credential
that writes into the database.

## The audit records that a credential changed, never anything about it
The secret columns are absent from `activityAttributes()`, so an ordinary save
records nothing; `SourceSecretAudit` writes the explicit, value-free entry
instead — who, when, which source, and for a rotation how long the old key
stays valid. Not the value, not its length, not the old value, **not even the
hint**: the audit log must not become the one place a credential is written
down in readable form. There are tests asserting none of it appears in a
response, a log line or an audit entry.

## The endpoint authenticates before it parses
`IngestController` resolves a source, asks `IngestGuard`, writes the body down
and answers **202**. Nothing is parsed, mapped or persisted on the request: the
sender is waiting, and a sender that waits times out and retries, and a retry
storm is how an integration takes an application down. 202 rather than 201
because nothing has been created yet, and saying otherwise would be a promise
the request has not kept.

The guard's order is load-bearing, cheapest first:

1. **address** — allowlist, no work done yet;
2. **size** — before any of the body is looked at;
3. **key** — a constant-time hash comparison;
4. **signature** — an HMAC over the raw bytes;
5. **replay**.

The body is a string of bytes from a stranger until a signature says otherwise.
Handing it to a JSON parser first would make the parser the thing standing
between a stranger and the application, which is why `$request->getContent()` is
read once and every later check works on that exact string — there is no window
in which the thing verified differs from the thing stored.

**A refused request stores nothing.** It leaves a log line with the address it
came from, and an authentication failure logs at `warning` while a malformed one
logs at `info`, because somebody trying keys is worth noticing and an
unfinished integration is not. Storing bodies for anybody who knows a uuid would
hand an unbounded write primitive to whoever guessed one. The key header is
never kept on the event either.

`IngestRefusal` decides every status in one place. Unknown, deleted and disabled
sources all answer a bare **404**; past that the answers are specific, because
reaching them means already holding a live address, and an integrator should not
have to guess between "my signature is wrong" and "my clock is wrong". A header
with no parseable timestamp is an **invalid signature**, not a stale one —
telling somebody to check their clock when they sent nonsense sends them looking
in the wrong place.

## A replay is the same signed request, not the same payload
The fingerprint is of the **signature header**, scoped to the source. A replay
presents the identical signed request, so that matches exactly and only then.
Fingerprinting the body would refuse a second legitimate delivery carrying the
same payload — two identical "task updated" events a minute apart are not an
attack, and 8.4's idempotency is what stops those becoming two records.

Unsigned deliveries are not replay-checked: without a signature there is nothing
that distinguishes a replay from a deliberate re-send.

## Both authentication checks are optional, neither may be off
The brief offers a key **and/or** a signature, so a source says which it uses,
and both default to true — the safe arrangement is the one somebody gets without
choosing. Turning both off is refused in the form, because a source that
authenticates nothing is an open door with a uuid on it. A source that demands a
signature but has no signing secret refuses everything, for the same reason:
accepting would be authenticating nothing.

An **empty IP allowlist means anywhere**, which is the honest default — most
integrations run somewhere with no fixed address, and a list nobody can keep
correct is a list that gets switched off. Entries are validated on the way in,
because a typo is a rule that silently matches nothing and looks exactly like a
rule that works.

The rate limit is keyed by **source**, not by address: two integrations behind
one proxy must not starve each other, and a source is the thing an administrator
can switch off when one misbehaves.

## The pipeline reuses what importing already declares
A CSV import and an inbound delivery are the same problem wearing different
clothes: an outside set of values arriving at a module that has already said
which fields may be written, what they must look like, and which action creates
one. `ImportSource` **is** that declaration, so `ImportBackedWriter` reads it
rather than keeping a second list — the second list is always the one that
drifts.

That is also why `IngestionTargets` lists the three modules importing covers and
not deals: a module becomes writable from outside when it declares those things,
and inventing that declaration inside Phase 8 would be Phase 8 deciding on the
module's behalf. A test asserts `IngestionTargets` and `IngestionWriters` name
the same modules, so neither can grow without the other.

What importing has no answer for is **update**, because a file only ever
creates. That is supplied per module by the same DTO and action the edit screen
uses, so an ingested change obeys every rule a typed-in one does. The
`ImportSource` contract itself is untouched — changing a Phase 2 interface to
suit Phase 8 would be Phase 8 reaching backwards.

**The update merges over the record's current values first**, and that is
load-bearing: a DTO's `fromArray()` fills every absent key with null, so handing
it the mapped row alone would blank every field the delivery did not carry. A
source mapping only an email would wipe the name, company and address off a
record the first time it updated one.

## Where each stage's decision lives
- **Filter** — all of a source's filters must pass; AND only, no OR, no nesting.
  A failing filter is `Skipped`, never `Failed`: a source that keeps one event
  type discards most of what it is sent, and calling that an error buries the
  real ones.
- **Map** — `target_field` is checked against the module's declaration **every
  time it is used**, not only when it was saved. A module that drops a field
  leaves mappings naming something that no longer exists.
- **Transform** — `ValueTransformer` is the seam 8.6 fills. An **unknown
  transform passes the value through** rather than failing: a mapping left by an
  older version should not turn a configuration mistake into an outage for every
  event that source sends.
- **Validate** — the module's own rules, the same ones an import obeys. A
  payload that would make a record the application refuses by hand is refused
  here too.
- **Dedupe** — the sender's own id first, looked up **through the event log**
  rather than a column on the business table. That keeps the idempotency key
  where it belongs — a fact about a delivery, not a field on a lead — and means
  no module has to grow a column to be ingestible. A blank match value matches
  **nothing**, or one delivery would update whichever record happened to have a
  blank in that column.
- **Assign** — the configured owner, else whoever set the source up. Neither
  means the delivery fails: a record with no owner is how the visibility scope
  springs a leak.
- **Trigger** — nothing. Creating the record through the module's own action
  fires the observers that run workflows, so there is deliberately no second
  path that could behave differently.

## The write is in a transaction; the account of it is not
If the write fails the record must roll back — but the row saying it failed has
to survive, or a failure leaves no trace and the event reads as though it were
never processed. So `fail()` is called after the transaction has unwound, never
inside it.

The error message is the exception class and text, **never the payload**: a
failure line that echoed the body would put customer data in the log, and the
column is read on a screen. It is truncated at 1000 characters because a
database error can echo an entire query.

`ProcessIntegrationEvent` carries an **id, not a model** — a serialised model is
a snapshot of a row this very job writes to — and runs once. The action catches
its own failures and records them, so a retry would re-run work already
accounted for, and a delivery that failed because the payload was wrong will
fail the same way again. Deliberate replay is 8.9's, after somebody has fixed
the mapping.

An event that is already settled is returned untouched, because a queue can
deliver a job twice and doing the work again would create a second record for
one delivery.

## Sandbox runs everything except the write
Mapping, transforming, validating and deduping all happen, so `mapped_output`
shows exactly what would have been written — and a mapping failure in sandbox
still **fails**, or sandbox mode would hide the very problems it exists to find.

## The mapping screen is built around a real payload
Mapping against the other system's documentation is mapping against what their
documentation *says* they send, which is rarely what they send. So the screen
listens for one delivery, keeps it, and turns every path in it into something to
click.

**Listen mode expires rather than being switched off**, and clears itself the
moment it catches something. A mode somebody turns on and forgets quietly
overwrites the sample months later, halfway through a conversation about why the
mapping stopped matching; and the point is *one* real example, not whatever
arrived last while somebody was still reading it.

The sample is captured in `CaptureIngestEventAction`, not in the pipeline, so a
payload the processor chokes on is still the one somebody can build a mapping
against — the payloads worth capturing are exactly the ones that do not work
yet.

`MappingSuggester` matches on the **last segment** of a path, because that is
where the name is: `contact.email`, `data.attributes.email` and `email` are all
the email and the prefix is somebody else's envelope. It only ever **adds** rows
for fields nothing is mapped to — overwriting a careful mapping with a guess is
the one thing a suggestion must not do — and it stays quiet below a similarity
threshold, because a wrong mapping that looks deliberate gets saved.

## The dry run is the real thing with the last step removed
`PayloadMapper` is one implementation used by both the pipeline and the dry run.
A preview produced by a second implementation is a preview of something else,
and the only reason anybody trusts a dry run is that it is not one.

It runs against what is **saved**, not what is on screen, because the next real
delivery uses the saved rows. It creates nothing and touches nothing.

Saving **replaces** the mapping rather than diffing it: the rows on screen are
the mapping, and reconciling by id would leave a row somebody deleted alive if
its id never reached the browser. A target field the module does not offer is
refused at save time as well as ignored at run time — storing one produces a
rule that silently does nothing.

Custom fields are written inside the same transaction as the record. A lead that
exists with half its custom fields is worse than one that does not exist.

## Transforms answer the differences that actually come up
The gap between two systems is rarely structural — the other side has the name,
the date and the status, it just writes them differently. So: one name where we
keep two, a date in the format that country writes dates in, a status vocabulary
that is theirs rather than ours, and a phone number with brackets in it.

Two rules govern all of them:

- **An unknown transform passes the value through.** A mapping left by an older
  version should not turn a configuration mistake into an outage for every event
  a source sends.
- **A transform that cannot do its job returns null, never a guess.** A date it
  cannot parse is not a date; writing today's instead puts a confident wrong
  answer in a column, which is worse than an empty one and far harder to notice.

The date format is **stated, not detected**. `03/04/2026` is April in most of
the world and March in the United States and there is nothing in the string that
says which — a parser left to guess is quietly wrong for eleven days of every
month. With no format given it falls back to Carbon, which covers ISO-8601 and
what most APIs actually send.

A name splits on the **last** whitespace group: everything before it is the
first name. That is right for "Maria del Carmen Okafor", where taking the second
word is not. A single word is a first name with no surname — somebody called
Cher is called Cher.

A value map matches case-insensitively, because a system sending "Open" today
sends "OPEN" the day somebody refactors it. An unmapped value falls to the
configured fallback and, with none, is **left alone rather than blanked**: a
status we have not seen is information, and validation is where it gets refused.
A map pointing at something the module does not accept therefore **fails the
delivery** rather than writing it — there is a test for that, because a wrong
translation table should be visible.

Order matters in the pipeline: transform first, **then** the default fills the
gap it left. A default applied first would never be reached.

## The rule builder edits what the pipeline reads, and nothing else
`transform_options` is filtered on save by what the transform declares it needs,
so switching a row from a value map to a date does not leave the old translation
table in the column waiting to confuse somebody.

A value map is a textarea of `theirs = ours` lines rather than a repeater of
paired inputs: a translation table is usually pasted in from somewhere, and
twelve rows of two inputs is a screen nobody fills in. A line with no `=` is
ignored rather than half stored.

Matching is per source, not per mapping: the sender's own id where they publish
one, otherwise a set of fields that must **all** match. Both are checked against
the module's declared fields on save, so a rule cannot name a column that does
not exist. Clearing the fields means always create, which is the right
arrangement for a source whose deliveries are genuinely events.

## Pull mode joins the same pipeline, it does not shortcut it
`SyncDataSourceAction` fetches and then writes **one `integration_events` row
per record**, exactly as a pushed delivery does, and dispatches the same job. A
pulled lead and a pushed one are mapped, deduplicated, validated and assigned by
the same code, and the event log answers "where did this come from" the same way
for both. A second path that wrote records directly would be a second set of
rules to keep in step, and the one that drifts is always the one nobody looks
at.

`signature_verified` is **false** on a pulled event. Nothing signed it — we
fetched it — and saying otherwise would make the log assert a guarantee that was
never made.

The pull URL goes through `WebhookTarget`, the same SSRF guard the outbound
webhook uses: an administrator typing a URL is still somebody typing a URL, and
this one is fetched with the server's own network access. Tests add hosts to the
fixed resolver map in `tests/Pest.php` rather than reaching for a real name —
see [[integrations]].

## The cursor is theirs, and it is written once
`pull_cursor` is **text**, not a timestamp: it is their value — an id, a
sequence number or a date — and we do not get to decide which. It is compared
with `strcmp`, which is right for an ISO date and for a zero-padded id, and for
anything else the value is only ever handed straight back to them.

It is saved **once, at the end of a run**. Advancing per record would leave the
mark past records a failed run never delivered, and those records would never be
fetched again. There is a test that fails page two and asserts the cursor did
not move.

A first run carries no cursor, because "import the backlog" is what a first run
means.

Pagination stops on a **short page** rather than on an empty one: asking for one
more page and getting nothing costs a request on every single run. `MAX_PAGES`
bounds it anyway, because their pagination is their code and a response that
always claims another page would otherwise fetch for ever — and a sync that
never finishes blocks every later one.

## A schedule is a fixed set, measured from the last run
Not a cron expression. A cron field on a settings screen is a field people get
wrong in ways that stay invisible until the sync has not run for a week, and
"every fifteen minutes" covers what anybody wants from a CRM integration.

Due-ness is measured from when the last run **happened**, not against a clock
slot: a sync that overran should not be immediately followed by another, and a
source added at twenty past should not wait forty minutes for its first run.

A half-configured source — no URL, switched off, still push — is skipped
silently. Somebody mid-setup is not an error worth waking anybody for.

One source failing does not stop the rest, and the failure is recorded on that
source rather than thrown: their outage is not our outage. Their **error body
never reaches the summary**, because their error page can contain anything,
including our own token echoed back.

## The pull credential follows the settings rule, not the DTO
It is encrypted (it has to be sent, so it cannot be hashed), it is in
`$hidden`, it is never loaded into the form, and a blank on submit means "keep
what is stored". Switching a source to push **clears** the URL and the
credential: a credential on a source that no longer fetches is a credential
nobody remembers is there.

The pull columns are written straight onto the source rather than through
`DataSourceData`, which deliberately carries only what both kinds of source
share. Adding a dozen pull-only parameters to the DTO would make every caller
know about a mode most of them never use. Two traps this hid at first: a value
spread into `fromArray()` that the DTO has no parameter for is **silently
dropped**, so nothing was persisted at all; and `Http::fake()` called twice in
one test **appends** its stub, leaving the first one still matching.

## What 8.1 deliberately does not build
- **No public ingest route.** Built in 8.3 — see above.
- **No secret columns.** Done in 8.2, in its own migration — see above.
- **No pull-mode endpoint, cursor or schedule.** 8.8 owns those.
- **No log viewer.** 8.9 owns the filterable event log, replay and the health
  dashboard, over `integration_events`.

## The log viewer is 8.9, and this screen is not it
`Settings\DataSources` is the short inline-form shape the webhooks screen uses,
because a settings screen is a list somebody visits to change one thing. The
event log gets the full data-view kit — all four modes, filters, replay — in
8.9, over `integration_events`.
