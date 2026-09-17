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

## One unapproved permission refuses the whole consent screen
Meta answers an OAuth request containing a scope the app has not been reviewed
for with **"Invalid Scopes"**, and the sign-in fails entirely — so
`leads_retrieval`, which needs App Review, locks a business out of Messenger,
WhatsApp and advertising as well.

`meta.scopes` therefore configures what is asked for, defaulting to everything
this application can use. Only names in `MetaAuthService::SCOPES` are honoured:
the value goes straight into a URL somebody is sent to. `missingScopes()` and
the connection tester compare against what was **requested**, so a permission
deliberately left out reads "not requested by this installation" rather than a
refusal nobody can ever clear.

Connecting with a system user token uses no scopes at all, which is the way in
while an app is still under review.

## Disconnecting keeps the rows and must stop claiming they work
Tokens are revoked at Meta and cleared locally, but the pages, ad accounts and
numbers keep their rows — every lead attributed to a form on one of those pages
points at them. So every setup step below the connection is gated on
`$account?->isUsable()`: without it the checklist goes on ticking "sending from
+880…" for a number that can no longer send anything.

## The browsed host beats the configured one, for Meta's addresses
A deployment is uploaded with the `.env` it was developed against, so `APP_URL`
routinely says `http://localhost:8080` on a site somebody is reading at its real
address — and the webhook panel and the OAuth `redirect_uri` were built from it.
`MetaUrls::base()` therefore prefers the host of the current request and falls
back to `APP_URL` only outside one. A private host is rejected on either side,
so browsing a development copy cannot overwrite a correct production address.

Do **not** guard that with `runningInConsole()`: it is true inside the test
suite, which switches the behaviour off exactly where it is being proved. A
console request reports APP_URL's own host, which the reachability check already
handles.

## Lead Ads is not in the default scopes
Meta refuses the entire consent screen over one permission the app has not been
approved for, so `leads_retrieval` — which needs App Review — is left out of
`MetaConfiguration::DEFAULT_SCOPES`. Including it locks a new installation out
of Messenger, WhatsApp and advertising as well. `meta.scopes` adds it back.

## The verify token is minted, not asked for
`ensureVerifyToken()` generates one on first use and never changes it: the value
is copied into Meta's webhook configuration, so a token that rotated on its own
would break every subscription already made with it. Disconnecting forgets it,
along with the app ID and secret.
