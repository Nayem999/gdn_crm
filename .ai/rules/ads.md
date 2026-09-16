---
paths:
  - 'app/Domain/Campaigns/**'
  - 'app/Domain/Meta/Ads/**'
  - 'app/Livewire/Meta/**'
---

# Ads

## Meta spend never writes campaigns.actual_cost
`campaigns.actual_cost` is a typed-in figure that only a person writes. A synced Meta spend must never be added to it, and linking a Meta campaign to a CRM campaign must not touch it.

Why: the column is what makes the module useful to the half of marketing that is people and exhibition stands. A figure an integration could overwrite is one nobody can correct, and a re-sync would silently rewrite what somebody typed.

How to apply: keep Meta spend in `meta_insights` and sum it at read time. Where a combined cost is wanted (12.13's analytics), add the two at display time and show them as separate lines, never merged into the column.

## Conversions are reported once, and the trigger is the attribution row
`meta_conversion_events.event_id` is deterministic — morph class, key and
outcome — and unique. A deal won, reopened and won again reports one Purchase.
Double counting does not merely misreport: Meta optimises delivery against what
it is told, so a phantom conversion teaches it to chase people who never bought.

Two traps behind the observer:

- `wasRecentlyCreated` stays **true** for the rest of that instance's life, so
  it cannot mean "this save is the insert".
- Lead conversion copies the lead's attribution onto the new deal **inside the
  transaction, without saving the deal again**, so no deal `saved` event ever
  fires with attribution present. `RecordAttribution` is therefore observed too:
  a record learning where it came from is exactly when its opportunity becomes
  reportable.

Meta answers a **rejected** event with HTTP 200 and the rejection inside
`messages`, so `events_received` is read rather than the status code trusted.
Identifiers are SHA-256 of the normalised value; `ctwa_clid` is sent unhashed
because it is not a person and hashing it makes it unmatchable.
