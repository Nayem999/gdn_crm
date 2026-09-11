---
paths:
  - 'app/Domain/Leads/Capture/**, app/Domain/Leads/Actions/SubmitLeadCaptureAction.php, app/Http/Controllers/LeadCaptureController.php, resources/views/lead-capture/**'
---

# Lead Capture

## The lead capture form is the only unauthenticated write path
`/f/{token}` is the one place the internet can write to this application without signing in. Everything about it is shaped by that, and none of it is decoration:

- The public address is a random 32-char token, never a slug of the name — a slug lets anyone enumerate an organisation's forms.
- A payload never names a column. Only keys the form declares, intersected with `CaptureField::catalogue()`, reach `LeadData`; owner and source come from the form. Do not add a field key that is not in the catalogue, and never widen it to arbitrary lead columns.
- Three spam signals: honeypot (`LeadCaptureForm::HONEYPOT`), a `Crypt`-signed minimum fill time (`CaptureTimestamp`, `MINIMUM_SECONDS`), and per-IP throttling on the route. Spam rejection renders the *same* success page as a real submission — telling a bot which signal caught it tells whoever wrote it what to change. An inactive form is the one rejection that speaks, because a person followed that link in good faith.
- `bootstrap/app.php` exempts `f/*` from CSRF, and that is the application's only exemption. It exists because the form is embedded in an iframe on third-party sites where cookies are unreliable. A test asserts the exemption list is exactly `['f/*']` — if you need to add one, that is a decision to take deliberately, not a test to update.
- The embed snippet is an iframe, never a script tag: a script would run our code on somebody else's page.
