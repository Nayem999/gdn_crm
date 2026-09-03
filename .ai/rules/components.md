---
paths:
  - resources/views/components/select.blade.php
  - resources/views/components/status-chip.blade.php
---

# Components

## x-select: the original prop API is load-bearing
Task 1.7 expanded `<x-select>`, but `name`, `label`, `options`, `selected`,
`placeholder`, `multiple` and `required` keep working exactly as before —
Company Profile (app/Livewire/Company/CompanyProfileForm.php) and
tests/Feature/SelectComponentTest.php depend on them. Everything added since is
optional and additive: `search-method`, `search-url`, `preload`, `create-event`,
`depends-on`, `hint`, `error`.

`options` accepts either shape:

- the flat `['value' => 'Label']` map the rest of the app uses, or
- rich rows: `['value' => .., 'label' => .., 'description' => .., 'color' => ..,
  'disabled' => ..]`.

Rich data reaches Tom Select through a `data-data` JSON attribute on each
`<option>`. Two traps, both of which cost a debugging round:

- Tom Select 2 defaults `dataAttr` to **null** (v1 defaulted to `'data-data'`),
  so without setting it the extra data is silently dropped and `render.option`
  only ever sees `value` and `text`.
- `dataAttr` is used as `element.dataset[dataAttr]`, so its value is the *dataset
  key* — `'data'` — not the attribute name `'data-data'`. Setting it to
  `'data-data'` fails just as silently.

`tomSelectField()` sets `dataAttr: 'data'`. Custom attributes like
`data-description` are never read; everything must go inside the JSON.

Blade tests can only prove the attribute is emitted, not that Tom Select reads
it — that part needs a browser check.

## The select never owns a create modal or a search route
`create-event` dispatches a browser event carrying the typed term; the page opens
its own modal and, once saved, dispatches `select-option-added` back at the field
to insert and select the new option. `search-method` calls a method on the parent
Livewire component, so authorization travels with the component rather than
needing a new open endpoint. Prefer it over `search-url`; if a module does add a
search route, that route owns its own authorization and scoping.

### A search-method's parameters come from the browser
Tom Select sends the query as `null` on a preload and on a cleared box, so a
`string $term` parameter produces a 500 that `loadPage`'s catch turns into a
silent "No matches found". Type them `?string $term = null, mixed $page` and
coerce. See .ai/rules/contacts.md for the case that found this.

`TomSelect.load()` takes the **query string**, never a callback. Paged loads are
appended with `addOptions()`; `load()` routes through `setupOptions`, which
replaces the whole list.

Alpine's `tomSelectField()` lives in resources/js/app.js and dispatches a native
`change` event so `wire:model` keeps working through Tom Select. `sortableList()`
and `kanbanColumn()` live beside it and report back via `$wire.call()`.

## x-status-chip takes an enum or a colour
`<x-status-chip :status="$enum" />` reads `color()` and `label()` off the enum.
Slot content wins over the enum's label. `color="..."` still works on its own, and
`dot` adds a leading dot. Colours are a fixed palette map — Tailwind cannot see
class names built at runtime, so add new colours to the map rather than
interpolating them.
