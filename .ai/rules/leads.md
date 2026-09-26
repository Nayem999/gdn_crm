---
paths:
  - 'app/Domain/Leads/**'
  - 'app/Livewire/Leads/**'
  - 'app/Jobs/RescoreLeads.php'
  - 'database/seeders/LeadScoringRulesSeeder.php'
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

## Scoring and qualification are the filter engine, not a second evaluator
A `LeadScoringRule` is one `FilterCondition` plus a meaning: points (kind
`score`) or a requirement for qualifying (kind `qualification`). Rules are
evaluated by handing the condition to `FilterApplier` against a `Lead` query —
`LeadRuleMatcher` is the only place that happens.

Evaluating in SQL rather than in PHP is deliberate. It means a rule saying
"estimated value is at least 5000" matches exactly the leads the filter chip
with the same condition returns, including the awkward parts: NULL-aware
negation, collation-driven case handling, date boundaries. Do not add an
in-memory evaluator "for speed" — there is a test asserting the two agree, and
a second implementation is a second set of edge cases.

`LeadRuleMatcher` never applies `visibleTo()`. A score belongs to the lead, not
to whoever is looking at it.

A rule whose field is no longer in `LeadFields::filters()`, or which lacks the
value its operator needs, matches **nothing** (`isUsable()` is false). It must
never fall through to matching everything.

## Scores are written by the query builder, never the model
`ScoreLeadsAction` updates through `toBase()`, so a rescore of the whole
database writes no audit entries and does not bump `updated_at`. `score` is also
absent from `Lead::$fillable` and from `activityAttributes()`: it is derived from
the rules, and the rules are what gets audited. Bulk scoring costs one query per
rule plus one id sweep — never one round trip per lead.

Rescoring happens after every write that could change a scoring input:
`CreateLeadAction`, `UpdateLeadAction` and both paths of
`ChangeLeadStatusAction` (status is itself a field a rule may read).

## Qualification defaults to open, and gates only the qualifying move
With no active `qualification` rules, `QualificationCheck::passes()` is true and
`ChangeLeadStatusAction` behaves exactly as it did before task 2.4. That is why
the seeder installs scoring rules but no requirements — gating the pipeline is
an opt-in decision, not a default that silently blocks a fresh installation.

A minimum score is expressed as an ordinary requirement on the `score` field, so
there is no special case for it. `guardQualification()` rescores before checking
precisely so that requirement is judged on a fresh score. A **scoring** rule may
not read `score` (`LeadFields::SCORE`) — that would define the score in terms of
itself, and `SaveLeadScoringRulesAction` drops such a row.

Status is a scoring input, so a rule listing only the earlier statuses would
penalise progress. The seeded "somebody has already worked it" rule therefore
includes Qualified.

## x-select behind wire:ignore needs a wire:key that tracks its options
On the scoring screen the Comparison dropdown's options depend on the chosen
field. `<x-select>` wraps Tom Select in `wire:ignore`, so a re-render cannot
update those options in place — the dropdown kept the previous field type's
comparisons and lost its selection. The fix is a `wire:key` on the wrapping div
that includes the field (and, for the value picker, the operator), so Livewire
replaces the node and Tom Select rebuilds. Any dependent `<x-select>` added
later needs the same treatment; tests can only assert the key is present and
changes, because the staleness itself is client-side.

## Conversion is one transaction, and it is idempotent
`ConvertLeadAction` creates the account, the person at it and optionally a deal,
stamps the lead with all three and closes it — all inside one transaction. A
lead that produced an account but no contact is worse than one never converted,
because the half-finished state looks finished.

Converting twice is not an error. `alreadyConverted()` returns the earlier
result instead of building a second set, which matters for a retry from a
browser that never saw the first response and will matter more when the Phase 8
gateway can trigger one. The conversion columns live on the lead rather than
being worked out from the three records, so "has this been converted, and into
what?" is one read and not a guess at a match.

`ChangeLeadStatusAction::force()` is called here and nowhere else: Converted is
absent from every transition list, so this is the one caller entitled to set it,
and only once the records genuinely exist.

An **unqualified** lead is refused — it has to be put back into play first.
Every other open status converts, deliberately: requiring Qualified would make
the 2.4 rules a gate on a workflow they were not written for.

The screen offers accounts and contacts that already match, using the 2.5
engine, so a lead from an existing customer joins that account rather than
starting a second one. Chosen ids are re-checked against `visibleTo()` on
submit: `exists` proves a record is real, never that this person may reach it.

## Deals arrived early, on purpose
`app/Domain/Deals` holds the minimum conversion needs: the table, the model, a
`DealStage` enum and a view-only policy. Phase 3 builds pipelines (3.1), the
full record and screens (3.2), the board (3.3) and stage history (3.4) on top —
`DealStage` becomes the default pipeline rather than being replaced, and
`probability()` moves to a per-stage setting.

## owner_id is gone — leads have several assignees, not one owner
Lead has no owner_id column. `lead_assignees` (via SyncLeadAssigneesAction, the only writer) holds every assignee; all of them are fully able to work the lead the moment they're added. `priority` is optional and only orders the escalation ladder (leads:escalate-assignments, hourly, settings key leads.escalation_hours) — it never gates who may act today.

Reading "the owner" of a Lead: use `primaryAssignee()` (highest-priority assignee, tie-broken by assigned_at) — see AssignmentResolver::recordOwner(), SendNotificationHandler::owner(), CreateRecordHandler::owner() for the special-case pattern every generic owner_id reader needs for Lead.

Lead::scopeVisibleTo() is fully overridden (whereHas('assignedUsers', ...) OR lead_owner_id) rather than using the shared ScopesByAccessLevel owner-column mechanism — a lead with zero assignees and no owner is invisible below `all` access, the same leak owner_id being NOT NULL used to close. The optional lead owner (lead_owner_id) sees the lead too; that was a deliberate change, it began as a label only.

Everybody newly put on a lead — assignees and a new owner — gets `leads.assigned`, never the actor who made the change. AnnounceLeadAssignmentAction is the one sender, fired after commit from CreateLeadAction, UpdateLeadAction (only the newly added) and SyncLeadAssigneesAction::add() (only when the row is new; a null actor reads as an automation). Add a new way to assign somebody and it must go through one of those.

Factories: LeadFactory::ownedBy($user) replaces the whole assignee set with just $user (what owner_id used to mean); assignedTo($user, $priority) adds one more assignee alongside whoever's already there, for testing the multi-assign concept itself. tests/Pest.php has leadOwnerId()/leadAssigneeIds() helpers — use them instead of ->owner_id in any test touching a Lead.
