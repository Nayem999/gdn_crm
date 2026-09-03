---
paths:
  - 'app/Domain/Accounts/**'
  - 'app/Livewire/Accounts/**'
  - 'resources/views/livewire/accounts/**'
---

# Accounts

## Account is not Company
`App\Domain\Accounts\Models\Account` is a customer organisation.
`App\Domain\Company\Models\Company` is the single organisation this CRM is
installed for. The brief heads the task "Accounts/Companies"; keep the two
apart, and never reach for Company when you mean a customer.

## The reference implementation for a list screen
`AccountsIndex` is the first real consumer of the 1.7 data-view kit, so copy its
shape for Contacts, Leads and everything after:

- `use ExportsDataView;` and `use WithDataView { cellFor as defaultCellFor; }` —
  the alias is what lets an override hand unstyled columns back to the kit.
- `dataViewBaseQuery()` applies `visibleTo(auth()->user())`. The component owns
  the query, so nothing outside the viewer's access level reaches any of the
  four views.
- `AccountFields` is the single declaration of columns, filter fields, sortable
  columns and search columns. The screen and the export both read it, so a
  column cannot be filterable on screen and not in a queued export.
- `AccountExportSource::exportQuery()` re-applies `visibleTo()`. A queued export
  runs without a session, so forgetting this would email someone rows they
  could not see.
- `cellFor()` renders chips and links once, and all four views inherit it. Use
  `ChipPalette::chip()` for server-side chips — Tailwind cannot see class names
  built at runtime, so every colour is written out in `ChipPalette`.

## Kanban needs a field with few values
Accounts group by **size band** (5), not industry (19). Nineteen columns push
every card off-screen. When a module has no status-like field, pick the smallest
meaningful grouping rather than the most descriptive one.

## Hierarchy guards live in three places, on purpose
`Account::canBeParentedBy()` refuses itself and its own descendants;
`UpdateAccountAction` refuses the same, so a tampered request cannot bypass the
form; and `ancestors()`/`descendants()` carry a `$seen` guard so an already
corrupted chain cannot hang a page render. Keep all three — there is a test for
each, including one that writes a loop straight to the column.

Deleting an account lifts its subsidiaries to the top level rather than
cascading. A subsidiary is a customer in its own right.

## Money
`annual_revenue` is `DECIMAL(15,2)`, and the form caps input at
`9999999999999.99` so MySQL cannot silently truncate a value that does not fit.
Read it with `NumberFormat::format()` so it follows the localisation settings.
