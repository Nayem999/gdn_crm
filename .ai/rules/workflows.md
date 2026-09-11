---
paths:
  - 'app/Domain/Workflows/**'
  - 'app/Livewire/Workflows/**'
  - 'app/Jobs/RunWorkflow.php'
  - 'database/migrations/*workflow*.php'
  - 'resources/views/livewire/workflows/**'
---

# Workflows

## Workflow conditions are filter-builder conditions, and the log outlives the definition
Two decisions the whole of Phase 5 rests on:

**Conditions reuse the filter engine.** `workflows.conditions` stores the filter builder's own array shape and `Workflow::conditions()` hands it back as a `FilterGroup`. 5.3 evaluates it the way `LeadRuleMatcher` already does — hand the group to `FilterApplier` against `whereKey($record)` and ask `exists()`. Evaluating in SQL rather than PHP is what makes a workflow condition and a filter chip agree by construction, including NULL-aware negation, collation-driven case handling and date boundaries. Do not write a second, in-PHP comparator for workflow conditions.

**The execution log survives the thing it describes.** `workflow_runs.workflow_id` and `workflow_run_steps.workflow_action_id` are nullOnDelete, and each row keeps its own copy (`workflow_name`, `action_type`). Somebody asking why a record changed last month needs the log after the workflow is deleted. `SaveWorkflowAction` reconciles steps in place rather than delete-and-reinsert for the same reason: a new id per save would detach the whole history from the steps it describes.

Also: the column is `trigger_event`, not `trigger` — TRIGGER is a MySQL reserved word. `workflow_runs.dedupe_key` is a nullable unique index; it is how 5.2's "fires exactly once" is enforced rather than hoped for, since a check-then-insert is one two queue workers can both pass. Null means "no claim", so replayed and ad-hoc runs are unconstrained.

## Suppression is read at event time; dispatch waits for the commit
`WorkflowObserver` deliberately does NOT implement `ShouldHandleEventsAfterCommit`, even though that is the obvious way to write it. That interface defers the whole handler, so by the time it runs, the action that wrapped its writes in `WorkflowSuppressor::while()` has already returned — suppression reads as off and a workflow re-triggers itself. The loop the suppressor exists to prevent happens anyway.

Instead: check `isSuppressed()` synchronously in the observer, compute the changed-field context there too (the model still knows what the save changed), and hand the dispatch to `DB::afterCommit()`. That keeps the rollback guarantee — a record created in a transaction that rolls back fires nothing — without moving the suppression decision.

Both `WorkflowCache` and `WorkflowSuppressor` must stay registered as container singletons. A fresh instance per resolution means the suppressor's depth is always zero (suppression silently does nothing) and the listening set is re-queried on every saved record.

Never write back to the `workflows` row on a firing. 5.1 had `run_count`/`last_run_at` and 5.2 dropped them: an UPDATE of one row on every event, contended by every concurrent insert, and `n + 1` read in PHP is a race. `workflow_runs` already holds what happened — derive counts from it.

## What a workflow step may touch, and where it may send
Three constraints every handler is built around. Keep them when adding an action type.

**A step writes only what a form could write.** `WorkflowModules::writableFields()` is the module's own declared field set intersected with the model's `$fillable`. That is why a workflow can filter on `created_at` and cannot rewrite it, and why no config can name `id`. Never reach past it to `forceFill` an arbitrary key from config.

**A status goes through the action that owns it.** `UpdateFieldHandler` routes the module's status column to `ChangeLeadStatusAction` / `MoveDealStageAction` rather than writing the column. Those actions hold the transition rules and the qualification checks, and a workflow writing the column directly would be the single route that skips them. A refused transition becomes a failed step whose message is the refusal — which is what the log should say. Activities are deliberately refused: complete/reopen/cancel each do more than set a column, and picking one from a config string would be choosing an action from data.

**An outbound URL is an SSRF primitive.** `WebhookTarget` refuses anything but http/https and anything resolving to a private, loopback or link-local address — 169.254.0.0/16 first, because that is the cloud metadata service. Every resolved address is checked, not just the first, and the client does not follow redirects (a redirect turns a checked public address into an unchecked private one). Known gap, stated in the class: DNS rebinding, which needs the connection pinned to the checked address and belongs with 7.10.

Handlers **return** `WorkflowStepOutcome`, they do not throw. `skipped` means there was nothing to do and that is fine (no address, nobody matched); `failed` means it should have worked. Getting that wrong puts a red row in the log every night for a rule that is simply not applicable, which buries the failures that matter.
