---
paths:
  - 'app/Domain/Contacts/**'
  - 'app/Livewire/Contacts/**'
  - 'resources/views/livewire/contacts/**'
---

# Contacts

Built on the same shape as Accounts — see .ai/rules/accounts.md for the list
screen pattern, which applies unchanged here.

## One primary contact per account, and the rule lives in one place
`SetPrimaryContactAction` owns the flag. Every write path goes through it, and
`ContactData::toAttributes()` deliberately omits `is_primary` so no form can
write it directly.

**Not a unique index**, and this is not laziness: MySQL treats every NULL as
distinct, so a unique index on `(account_id, is_primary)` would allow two
primaries once `account_id` is NULL, and would *forbid* two non-primary contacts
at the same account — the opposite of what is wanted.

The behaviours, all tested:

- the first contact at an account becomes its primary; later ones do not
- promoting demotes the previous holder, and only within that account
- a contact with no account cannot be primary — "primary" is relative to one
- moving a contact to another account drops the flag and backfills the old one
- removing the primary passes the flag to the longest-standing colleague

## Sorting the name column sorts by surname
There is no `name` column — `Column('name', sortColumn: 'last_name')`. Sorting a
displayed full name by `first_name` surprises anyone scanning a list.

Search matches either name part *and* the two concatenated, so "Dana Scully"
finds someone no single column holds.

## Server-side search: what the browser actually sends
The account picker is the first real `search-method` consumer, and it exposed
two defects in the 1.7 kit. Both are fixed; do not reintroduce either.

1. **A search method must accept null.** Tom Select passes the query as `null`
   on a preload and on a cleared box. `searchAccounts(string $term = '')` turned
   that into a 500, which `loadPage`'s catch swallowed into a silent "No matches
   found". Type these parameters loosely — `?string $term = null, mixed $page` —
   and coerce. A test that only passes `''` will not catch it.

2. **`TomSelect.load()` takes the query string, not a callback.** Passing a
   function meant infinite scroll never fetched page two. `watchForMoreResults()`
   now calls `loadPage()` itself and appends with `addOptions()` —
   `load()`/`loadCallback` route through `setupOptions`, which *replaces* the
   option list and would discard page one.

A search method must re-apply `visibleTo()`: `exists` proves a record is real,
never that this person may reach it. `ContactForm::save()` checks visibility
again after validation for the same reason.
