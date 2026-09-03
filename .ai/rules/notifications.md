---
paths:
  - 'app/Domain/Notifications/**'
  - 'app/Livewire/Notifications/**'
  - 'app/Jobs/SendNotification.php'
  - 'app/Notifications/**'
  - 'app/Mail/**'
  - 'resources/views/livewire/notifications/**'
---

# Notifications

## NotificationEventRegistry is the only source of events
Nothing outside it can be dispatched, appear in the matrix, or be given a
template — the same rule `PermissionCatalogue` and `SettingsRegistry` follow. A
module adds its events there and they show up in the matrix, the template editor
and the per-user preferences automatically.

Every event declares which `RecipientType`s it can reach and which merge fields
it carries. A template naming a field the event does not declare is refused at
save time, because it would otherwise render as a gap in a message someone reads.

## Call the engine through Notifier, and pass recipients in
`Notifier::send($event, $recipients, $data, $actor, $url)` — or `sendToAdmins()`
for "everyone holding this permission". Never touch a driver, a template or the
queue directly.

**The engine does not resolve recipients.** Only the module owning a record knows
who its customer, assigned agent or watchers are, so callers hand
`Recipient` objects in. `AdminRecipients` is the one resolver the engine owns,
because "people holding permission X" is the same everywhere.

## The gates, in order
1. the event must be registered
2. the admin matrix must have the channel on for that recipient type
3. the recipient's own preference can veto it — but never turn it back on
4. an unconfigured channel is skipped and logged, not attempted
5. quiet hours delay an intrusive channel; in-app is never held
6. the per-recipient hourly ceiling is applied in the job, at send time

Two consequences worth keeping: an actor is never notified about their own
action, and a person who appears twice in the recipient list hears once.

## Only mutes are stored, except for event-level exceptions
`UserNotificationPreferences` stores a row when someone turns something *off*.
Turning a whole channel back on deletes the row rather than writing a positive
override. An **event-level** row is stored either way, because "mute in-app,
except invitations" is a real instruction the wide mute must not swallow.

## Quiet hours delay, they do not drop
A notification suppressed at 2am is still wanted at 7am. `QuietHours::delayFor()`
returns a delay for the job; the log row is written either way, so nothing
disappears silently.

## SMS and WhatsApp are contracted but not connected
Both drivers implement `ChannelDriver` and report `isConfigured() === false`
until task 7.6 registers their settings groups and provider clients. They are
skipped with a reason rather than attempted, and the matrix says so. Do not write
a stand-in provider call — it would only invent an API 7.6 replaces.

## Templates are text, never code
`TemplateRenderer` scans for `{{ dotted.field }}` and nothing else. A template is
never compiled, evaluated or treated as Blade, and a merge *value* that looks
like a placeholder is inserted rather than re-scanned. Keep it that way: admins
author these, and so, indirectly, does anything that reaches the merge data.

## The log records what happened, not what was said
`notification_logs` deliberately has no body column — a rendered message carries
personal data and the log's job is delivery, not archival. Error text is
truncated to 500 characters because a provider can echo the payload back inside
one.

## Do not add a route at /settings/{registry group}
`/settings/notification-rules` is not a stylistic choice. A route registered at
`/settings/notifications` shadows the settings registry group of the same name
and makes it unreachable, which is exactly what happened here and was caught only
in the browser. Two tests in tests/Feature/Settings/SettingsScreenTest.php now
guard it: every registry group must resolve to `settings.group`.
