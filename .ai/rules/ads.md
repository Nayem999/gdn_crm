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

## Rates do not add, and reach is not unique people
Meta stores a CTR and a CPC **per day**. Summing them weighs a day with two
impressions as heavily as one with twenty thousand, so over any period the
figure is derived — clicks over impressions, spend over clicks — which is what
Ads Manager shows for a range too. A campaign with no impressions has **no** CTR
rather than 0%: the dash and the zero are different claims.

Reach is summed and is therefore *not* unique people: Meta counts a person once
per day, so somebody reached on two days counts twice. Ads Manager's own range
figure will be lower, and the screen says so rather than quietly disagreeing.

## The webhook verify token is shown, on purpose
It is stored as a secret, which makes it write-only on the settings screen — and
its entire purpose is to be pasted into Meta, so an administrator who could not
read it back had no way to finish the setup. It is displayed on the connection
screen to anyone holding `meta.manage`. Knowing it only lets somebody verify a
webhook they already control; what protects a delivery is the app secret's
signature, which is never shown. One token serves all three channels.

## Meta's addresses are always https, and never the request's scheme
`MetaUrls` builds the OAuth redirect and the three webhook addresses from
`config('app.url')` with the scheme forced to https, because Meta refuses an
`http://` redirect outright — "Facebook has detected that this app isn't using a
secure connection" — and will not deliver a webhook to one either.

Behind a proxy that terminates TLS the application sees plain HTTP on every
request, so `route()` generates exactly the URL Meta rejects while the site
itself is served over https. Forcing the scheme fixes Meta; it does **not** fix
password reset links or asset URLs, so `looksMisconfigured()` says so on screen
and `TRUSTED_PROXIES` is the real cure.

The scheme is forced only for a publicly reachable host: `https://localhost:8080`
is not a better answer than the http one, it is an address that serves nothing.

Both sides of OAuth ask `MetaUrls::callback()` — Meta compares the redirect_uri
sent at the consent screen with the one sent at the exchange and refuses a
mismatch, so they cannot be generated independently.
