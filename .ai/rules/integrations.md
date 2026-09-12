---
paths:
  - 'app/Domain/Api/**'
  - 'app/Domain/Webhooks/**'
  - 'app/Domain/Messaging/**'
  - 'app/Domain/Chat/**'
  - 'app/Http/Controllers/Api/**'
  - 'app/Http/Controllers/ChatCaptureController.php'
  - 'app/Jobs/DeliverWebhook.php'
  - routes/api.php
  - config/cors.php
---

# Integrations — API, webhooks, messaging, chat

## An API key is a person, not a service account
Sanctum authenticates a token to the user who made it, so every policy and
access level applies unchanged. There is deliberately no service account with
its own permission set: that would be a second set of rules to keep in step with
the first, and the one that drifts is always the one nobody looks at.

A record outside the caller's access level answers **404, not 403**. Telling
somebody a record exists but is not theirs is telling them it exists.

## The API throttle is keyed on the bearer token, not the user
`ThrottleRequests` runs *before* `Authenticate` in Laravel's middleware order,
so `$request->user()` inside the limiter is null and every key in the
organisation would share one bucket keyed by IP. The limiter hashes the bearer
token instead. The guard test bursts one key and then checks a second one still
works — if that test ever starts failing, this is why.

## The API surface is a short, explicit list
`ApiModules` names four modules. An API surface is a promise to keep working in
the shape it went out in; making it automatic would ship that promise by
accident every time somebody built a screen. Responses are declared field by
field for the same reason — serialising the model publishes the next column
somebody adds, the day they add it.

Webhook events are built from the same list, and the webhook payload *is* the
API's representation, so an integration learns one shape.

## Webhook payloads are captured at the moment of the event
`DispatchWebhookAction` builds the payload and stores it on the delivery. A job
that looked the record up when it ran would report the state at delivery time —
a different fact after a retry an hour later, and no fact at all after a
deletion.

## Webhook retries belong to the queue
`DeliverWebhook` throws on a non-2xx so the queue retries it; it does not loop.
A quiet `return` would mark the job done and leave the delivery at "trying" for
ever. Backoff is in minutes because the failures worth retrying are somebody
else's deploy or outage.

## The signature covers the timestamp, and that is the point
`WebhookSignature` signs `"{timestamp}.{body}"` and sends both in one header
(`t=…,v1=…`, the format Stripe made familiar). A signature over the body alone
verifies for ever, so anyone who captures one request can replay it tomorrow.
`verify()` is the receiver's check written out, so the tests exercise the real
thing rather than a restatement of it.

Outbound webhooks go through the same SSRF guard the workflow action uses: an
administrator typing a URL is still somebody typing a URL.

## The chat widget is a kind of lead capture form
Same table, `kind` says which. Both are a token in a URL, an owner who gets the
lead, a source and an on/off switch. A chat token cannot be used on the form
route and a form token cannot be used on the chat route — a public form has a
honeypot and a timing check the chat interface never runs.

A conversation becomes a lead only when there is an address or a number to reach
it on, and after that it updates that one lead however many messages arrive.

## CORS is published for the two public paths only
`config/cors.php` lists `f/*` and `c/*`. A blanket `*` would put the
authenticated application behind the same permissive header. Credentials stay
off: these endpoints authenticate with the token in their path, not a cookie.

## Messaging providers mirror the mail providers exactly
Same contract shape, same `{provider}_{field}` settings keys, same generic
tester panel — see [[mail]]. Two lists, because Vonage does not send WhatsApp
and Meta's Cloud API does not send SMS.

**Vonage answers HTTP 200 to a rejected message.** Acceptance is
`messages[0].status`, where anything but `"0"` is a failure. A driver that
trusts the status code reports every message as sent.

WhatsApp free-form messages are only allowed within 24 hours of the customer's
last message; outside that window Meta requires an approved template and refuses
anything else. That refusal is surfaced, not retried.

## API documentation is generated, never written
`OpenApiDocument` assembles the description from the registry: endpoints from
`ApiModules`, request fields from the same `rules()` the controller validates
against, response fields from each module's `schema()`. Hand-written API docs
are docs that were true once — a field is added, nobody edits the page, and the
integration that trusted it breaks at the customer's end.

`schema()` is the one hand-declared part, so a guard test renders a real record
through `toArray()` and asserts the keys match exactly. If that test fails, the
declaration has fallen behind the response — fix the declaration, do not relax
the test.

The readable page renders that same document rather than describing the API a
second time. Two descriptions of one API is one description and one lie waiting
to happen.

`php artisan api:docs` writes the document to a file and fails loudly if it
cannot — that is what a build runs. Nothing reads the file at runtime: the
`/api/v1/openapi.json` route assembles it live, so it cannot be stale.

## The suite must not touch DNS
`WebhookTarget::resolveUsing()` exists so tests can install a fixed map;
`tests/Pest.php` does. Before that, every webhook test did a real lookup and the
suite failed four tests at once under load. Leave the map in place, and add a
host to it rather than reaching for a real name.
