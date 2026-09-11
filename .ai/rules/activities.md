---
paths:
  - 'app/Domain/Activities/**'
  - 'app/Livewire/Activities/**'
  - 'app/Console/Commands/{SendActivityReminders,GenerateRecurringActivities}.php'
  - 'resources/views/livewire/activities/**'
---

# Activities

## "Activity" means two different things — the scheduled one and the audit one
`App\Domain\Activities\Models\Activity` is a task, call or meeting. `Spatie\Activitylog\Models\Activity` is an audit entry, and `RecordsActivity` gives every audited model — including this one — an `activities()` relation returning those.

So the relation from a lead, contact, account or deal to its tasks and calls is `scheduledActivities()`, never `activities()`. Adding the obvious name to a model would shadow the audit relation and silently break the timeline and the audit viewer for that module. Same reason the table is `activities` and the audit one is `activity_log` — that pairing is spatie's, not ours to rename.

## Occurrences are real rows in a rolling window, and reminders are a sweep
Recurrence materialises rows up to `GenerateRecurringActivitiesAction::HORIZON_DAYS` (90) ahead, not dates computed at read time: an occurrence has to be filterable, assignable and completable on its own, and an endless series needs a bound. The generator is idempotent and runs both on save and nightly. It reads existing occurrences `withTrashed()` — that is the whole of the idempotence, and without it the nightly sweep resurrects every occurrence somebody deleted.

Reminders are one indexed query per minute (`SendActivityRemindersAction`), not a delayed job per activity. A per-activity job would have to be found and cancelled every time a due date moved, and a queue missing that job looks identical to one that lost it. The lead time is per row, so the cut-off is `DATE_SUB(due_at, INTERVAL reminder_minutes_before MINUTE) <= now`, compared against the column rather than one fixed time. Anything more than `STALE_AFTER_HOURS` (24) late is written off — it is already on the overdue list.

`status` is not in `Activity::$fillable`. Complete, reopen and cancel own it, the way `MoveDealStageAction` owns a deal's stage.
