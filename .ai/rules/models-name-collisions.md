---
paths:
  - 'app/Domain/*/Models/*.php'
---

# A method named after a column needs a default attribute

`Account::industry()`, `Lead::status()`, `Deal::stage()` and friends are typed
accessors that share their name with the column they read. That reads well and
is the house style — keep it — but it has a trap that cost a real bug in 2.6.

Laravel decides whether `$model->industry` is a relation by looking for a method
of that name. While the attribute is present the property read never gets that
far. The moment it is **missing**, Laravel calls the method expecting a
`Relation`, and you get either infinite recursion or:

    Account::industry must return a relationship instance, but "null" was returned

The attribute goes missing more often than it sounds: `Model::create()` keeps
only what it was given, so any code path that creates a record without setting
that column produces an instance that explodes on the next read of it. Lead
conversion was the first such path — it creates an account from a lead, which
knows nothing about industry — and the crash came from spatie's activity logger
reading the attribute, not from the accessor at all.

So, two rules, both needed:

1. **Declare a default in `$attributes`** for every column that shares its name
   with a method. This is what actually fixes it, because it keeps the key
   present on every instance however the record was made. Use the same default
   the column has.
2. **Read it with `getAttributeValue()`** inside the accessor rather than
   `$this->thing`, so the method cannot re-enter itself.

`LeadConversionTest` walks every model, finds each method whose name matches a
column, and fails if there is no default for it — so adding a new accessor of
this shape without a default is caught rather than discovered later.

## The guard test discovers models, it does not list them
`LeadConversionTest` globs `app/Domain/*/Models/*.php` rather than naming
classes, because a list only covers what somebody remembered. Switching it to
discovery immediately turned up three more instances the hand-written list had
missed — `NotificationLog::status()`, `NotificationLog::channel()` and
`Setting::type()`, each a crash waiting for a partially-created instance.

Two Pest details that dataset needs:

- Pass a **closure** to `->with()`. A plain array is resolved at collection
  time, before the application is booted.
- Build the path from `__DIR__`, not `app_path()`. At collection time the
  container is not the full application and the path helpers are not bound.

## A `media` column shadows MediaLibrary's relation
Never name a column `media` on a model that uses InteractsWithMedia. Eloquent's attribute wins over the `media()` relation, so `getFirstMedia()` receives your JSON and throws a TypeError at runtime — the model still passes every test that does not touch a file. `social_messages` had to be migrated from `media` to `attachments` for exactly this. Same trap for any column sharing a name with a trait's relation (`tags`, `activities`).
