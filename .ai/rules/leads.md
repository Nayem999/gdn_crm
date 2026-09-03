---
paths:
  - 'app/Domain/Leads/**'
  - 'app/Livewire/Leads/**'
  - 'resources/views/livewire/leads/**'
---

# Leads

Same list-screen shape as Accounts and Contacts — see .ai/rules/accounts.md and
.ai/rules/data-view.md. What is different about leads is the status pipeline.

## One action moves a lead's status, and nothing else may
`ChangeLeadStatusAction` is the only writer of `status` and `status_changed_at`.
It refuses a move that `LeadStatus::canTransitionTo()` does not allow, and a
move to the status the lead is already in is a no-op that leaves the stamp
untouched (so `daysInStatus()` does not reset when somebody re-clicks).

This is enforced by leaving status *out* of the write paths rather than by
checking it in each of them:

- `LeadData` has no `status` field, so no form or payload can carry one.
- `CreateLeadAction` forces `New` and stamps `status_changed_at`, whatever the
  caller asks for.
- `LeadForm` has no status control.
- `LeadsIndex::moveCard()` overrides the kit's version to call the action, and
  reports the refusal reason through `notify` instead of silently ignoring it.
- `LeadShow` only renders `allowedTransitions()`, so the page never offers a
  move that would be refused — but `changeStatus()` still goes through the
  action, because the button list is not a security boundary.

Add a status and you must add it to `LeadStatus::allowedTransitions()`,
`pipeline()` and `color()`; the dataset tests fail loudly otherwise.

## Converted is reachable only through conversion
`Converted` appears in no `allowedTransitions()` list, in either direction:
nothing can be moved into it and a converted lead cannot be moved out. Task
2.6's conversion uses `ChangeLeadStatusAction::force()`, which exists for that
one caller. Do not widen `force()` into a general escape hatch, and do not add
`Converted` to a transition list to make a form work.

## company_name is a string on purpose
A lead is somebody nobody has matched to an account yet, so the organisation is
free text with an index on it, not an `accounts` foreign key. Conversion is
where the account gets created.

## Email or phone, not both required
`LeadForm` uses `required_without` in both directions: a lead captured from a
phone call has no email, and one from a web form has no phone, but a lead with
neither cannot be followed up.

## The board is by status and sums estimated_value
`dataViewKanbanField()` is `status` and `dataViewKanbanSumField()` is
`estimated_value`, which is what drove the per-column counting and lazy loading
in the shared kit — see the kanban section of .ai/rules/data-view.md.
