---
paths:
  - 'app/Domain/Settings/**'
  - 'app/Livewire/Settings/**'
  - 'resources/views/livewire/settings/**'
  - resources/views/components/settings-shell.blade.php
  - resources/views/components/form/secret.blade.php
  - app/Domain/Settings/DisplayTime.php
  - app/Domain/Settings/SettingField.php
---

# Settings

## SettingsRegistry is the only source of writable settings
Nothing outside `App\Domain\Settings\SettingsRegistry` can be stored.
`SettingsManager::set()` and `SaveSettingsAction` resolve every name there first
and refuse anything undeclared rather than creating it, and the row's `type` and
`is_secret` always come from the registry, never from a caller or a form. It is
to Settings what `PermissionCatalogue` is to roles.

A name is `{group}.{key}` — the first dot splits it, so a key may itself contain
dots (`mail.mailgun.secret` is group `mail`, key `mailgun.secret`).

When a later phase adds a provider group, add it to the registry, add any new
permission to `PermissionCatalogue`, and re-run `RolesAndPermissionsSeeder`.

## Secrets: encrypted at rest, write-only in the UI, redacted in the trail
- `is_secret` rows are stored as `Crypt` ciphertext. `Setting::$hidden` keeps
  `value` out of anything that serialises a model.
- `SettingsGroup` never loads a stored secret into component state, so it cannot
  reach the browser through the HTML or the Livewire snapshot. The form only
  holds a replacement the administrator just typed. A blank secret on submit
  means "keep what is stored"; clearing one is an explicit action.
- `SettingsManager::forGroup()` deliberately omits secrets. Read one by name when
  a driver genuinely needs it.
- The audit entry records the group, the changed key names and — for ordinary
  settings only — the new value. A secret is recorded as `[secret changed]`,
  never its value, length, or old value.
- `settings.secrets` is a permission of its own, separate from `settings.update`.
  Without it the secret fields are not rendered at all and a submitted secret key
  is dropped server-side by `visibleFields()`.

## The cache stores ciphertext, and invalidates by key
Reads go through a per-group cache entry (`settings:group:{group}`). What is
cached is the *stored* text, so a secret is cached encrypted — caching the
decrypted value would write the plaintext into the cache store, which here is the
database, and undo encryption at rest. Decryption happens after the cache read.

The cache driver is `database`, which has no tag support, so `flush()` forgets
explicit keys. Any new write path must call it.

## Two Laravel traps this framework already hit
- **`required` trims strings.** A setting whose value is a single space can never
  be saved — `required` sees `''`. Separators are therefore stored as tokens
  (`comma`, `space`) and turned into glyphs by `App\Domain\Settings\NumberFormat`.
- **`in:a,b,c` cannot express a comma-valued option.** `SettingField::rules()`
  returns an array using `Rule::in()` rather than a pipe-delimited string.

## The settings shell takes its active item from the caller
`<x-settings-shell>` must be given `active` (a route name) and `active-params`.
It cannot derive them from the request: during a Livewire update the request is
`POST /livewire/update`, so anything URL-derived loses the highlight after the
first interaction on the page. Every settings screen passes its own.

`SettingsNavigation::for($user)` filters entries by permission, and
`landingRouteFor()` gives the sidebar the first page the viewer can actually
open — the sidebar link is hidden entirely when there is none, rather than
pointing at a guaranteed 403.

## Stored time is UTC; the company timezone is a display timezone
`app.timezone` is UTC and every datetime column holds UTC. The timezone on the company profile is what the **office reads**, and until 3.6 nothing connected the two — the same gap the `localisation.date_format` / `time_format` / `week_starts_on` settings had.

`DisplayTime` is the only place that converts. `display()` takes a stored moment to the office clock, `store()` takes what somebody typed back. Do not call `setTimezone` anywhere else, and do not compare a stored value against a displayed one.

The consequence worth knowing: a task stored at 23:30 UTC is *tomorrow* in Dhaka. So a calendar window is chosen in display terms and converted before it hits SQL (`CalendarPeriod::from()`/`to()`), never queried as though the stored day and the displayed day were the same. Changing the company timezone moves every existing row's apparent time by the offset; that is inherent to having a display timezone at all, not a bug.

## A select whose values are numbers must be an Integer field
PHP turns a numeric array key into an int however it was quoted, so `SettingField::select('x', 'X', ['30' => '30 minutes'])` produces int keys. A `SettingType::String` field's own `string` rule then rejects the very options it offers, and `Rule::in(array_keys(...))` carries ints into a string field.

Use `SettingField::numberSelect()` for those — it declares `SettingType::Integer`, so the rules pass and `settings()` hands back an int rather than a string somebody has to cast.

`SettingsFrameworkTest`'s "every select option passes its own rules" is what catches this; it found both scheduling fields the moment they were added. The same int-key trap applies to `FilterField::select()` options and to any enum `options()` map keyed by a numeric value — see [[activities]] on ActivityPriority.
