---
paths:
  - 'app/Domain/Campaigns/**'
  - 'app/Domain/Meta/Ads/**'
  - 'app/Livewire/Meta/**'
---

# Ads

## Meta spend never writes campaigns.actual_cost
`campaigns.actual_cost` is a typed-in figure that only a person writes. A synced Meta spend must never be added to it, and linking a Meta campaign to a CRM campaign must not touch it.

Why: the column is what makes the module useful to the half of marketing that is people and exhibition stands. A figure an integration could overwrite is one nobody can correct, and a re-sync would silently rewrite what somebody typed.

How to apply: keep Meta spend in `meta_insights` and sum it at read time. Where a combined cost is wanted (12.13's analytics), add the two at display time and show them as separate lines, never merged into the column.
