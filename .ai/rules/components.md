---
paths:
  - resources/views/components/select.blade.php
---

# Components

## x-select is intentionally minimal until Task 1.7
`<x-select>` (Tom Select-backed) currently only supports static options, single or multiple selection, and forwards arbitrary attributes (including `wire:model`) to the underlying `<select>`. No server-side/AJAX search, no create-on-the-fly modal, no cascading selects, no rich options (avatar/subtitle/status dot) yet — those are Task 1.7's job.

When 1.7 builds those out, keep the existing prop API (`name`, `label`, `options`, `selected`, `placeholder`, `multiple`, `required`) working unchanged — Company Profile (app/Livewire/Company/CompanyProfileForm.php) and its tests (tests/Feature/SelectComponentTest.php) already depend on it. Add new props/slots for the new capabilities rather than renaming existing ones.

Alpine's tomSelectField() component lives in resources/js/app.js; it dispatches a native `change` event on the wrapped `<select>` so Livewire's `wire:model` binding keeps working through Tom Select.
