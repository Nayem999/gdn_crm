---
paths:
  - 'app/Domain/Mail/**'
  - 'app/Domain/Notifications/Drivers/MailDriver.php'
  - config/mail.php
---

# Email providers

## The `crm` mailer is the application's mailer, and its transport is settings-driven
`config/mail.php` defaults to the `crm` mailer, registered in `AppServiceProvider::boot()`
as `Mail::extend('crm', …)` and backed by `App\Domain\Mail\Transports\ManagedTransport`.

`ManagedTransport` resolves the configured provider **on every send**, not when
the mailer is built. That is the whole point of it. Laravel caches a resolved
mailer by name, so anything that reads settings at mailer-construction time is
correct in the web request that changed the setting and wrong forever in a
Horizon worker, which was started hours earlier and holds the mailer it built
then. Do not "optimise" the per-send read into a constructor; the settings cache
already makes it a memory lookup.

Built provider transports *are* memoised, keyed by provider plus a hash of its
credentials — so a batch of messages reuses one SMTP connection, and a changed
password still rebuilds.

## Every provider's credentials live side by side in one `mail` group
The group is assembled by `MailProviders::settingFields()`, not written out in
`SettingsRegistry`. Every provider's fields are declared at once, whichever one
is active, so switching provider does not destroy the credentials of the one you
switched away from — which is what makes switching back free and makes the
fallback provider possible.

Keys are `{provider}_{field}` with an **underscore, never a dot**. A dotted key
is a nested path to both Livewire's `wire:model` and the validator, so
`values.smtp.host` would write `['smtp' => ['host' => …]]` and the flat registry
key would never be filled. A test asserts no mail key contains a dot.

## `log` is a provider, not an absence
A fresh install and every developer machine has credentials for nothing.
`LogProvider` makes that a state the system can be in rather than a hole: it
counts as configured, because it does exactly what it says. `mail.provider`
defaults to it.

An unrecognised stored provider key also reads as `log` rather than throwing — a
mailer that refuses to be built takes the request down with it.

## SES goes over SMTP, deliberately
The SES API is SigV4-signed and in practice means adding the AWS SDK, which is a
dependency change. The SMTP endpoint is AWS's own documented alternative and
needs nothing. The cost is that it wants **SES SMTP credentials** from the SES
console, not an IAM access key — the field labels say so, because the two look
alike and the failure is an opaque authentication error.

## The fallback can double-send, and that is inherent
`ManagedTransport` falls back when the primary **throws**. A provider that
accepts the message and then times out on the response also throws, and the
fallback sends it again. A duplicate is the better of the two failures, but it
is a real one — do not describe the fallback as safe.

A fallback equal to the active provider reads as no fallback: retrying the thing
that just failed is not a fallback.

## API transports keep the provider's message id
`ApiTransport::doSend()` writes the returned id onto the `SentMessage`. It is the
only thing joining a message we sent to the bounce, complaint or open the
provider reports later; 7.3's delivery log is built on it, so a transport that
drops it makes that log unjoinable after the fact. Mailgun and Brevo wrap the id
in angle brackets and SendGrid returns it in the `X-Message-Id` **header** with
an empty 202 body — all three are handled, none are obvious.

## "Is email configured?" is the provider's answer, not a config key's presence
`MailDriver::isConfigured()` asks `MailConfiguration`, which asks the active
provider for its `missingRequirements()`. An SMTP provider with no host is
configured as far as `config('mail.default')` is concerned and cannot send a
thing. The requirements come back as *labels* ("a sending domain") because they
are read by the person looking at the form: "Mailgun needs a sending domain and
a private API key."

## The from address is stamped in the transport
`ManagedTransport` puts `mail.from_address` on a message whose From is still the
environment file's default — which is how "nobody chose one" arrives at a
transport, since Laravel fills the From from config before the message gets
there. A From that differs was set deliberately (a quote going out under a
salesperson's name) and is left alone.
