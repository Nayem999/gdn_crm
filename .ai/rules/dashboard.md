---
paths:
  - 'app/Domain/Dashboard/**'
  - 'app/Livewire/Dashboard/**'
  - 'resources/views/dashboard.blade.php'
  - 'resources/views/livewire/dashboard/**'
---

# Dashboard

## A dashboard widget asks DashboardScope, it never builds its own query
A total is still information about records somebody cannot open, so the dashboard is the easiest screen on which to leak one. `DashboardScope` holds both gates in one place: the **permission** decides whether a widget exists at all, and the module's own `visibleTo()` decides what it counts. No widget touches a model class directly.

`query()` returns **null** rather than an empty builder when the viewer lacks the permission. "No permission" and "no records" are different answers — a zero says "there are none", which is wrong — so a KPI card for a module the viewer cannot see is left out entirely rather than rendered as 0.

The module list comes from `ActivityRelations`, not a registry of the dashboard's own: it is the only one already pairing a module key with a viewer-scoped query, and a second list of the same four modules is a second thing to forget.

The feed is the audit trail, scoped. That is the difference from `ActivityLogIndex`, which is gated by `audit.view` and deliberately unscoped because an administrator reading the trail needs everything. Feed rows go through `TimelineEntry::fromActivity()` and render with `<x-timeline-entry>`, so one audit entry reads identically on the dashboard and on a record's timeline. Subjects are matched by type **and** id per module, through a `whereIn` subquery rather than a materialised id list.
