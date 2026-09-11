---
paths:
  - 'app/Domain/Workflows/**'
  - 'app/Livewire/Workflows/**'
  - 'database/migrations/*_create_workflow_tables.php'
---

# Workflows

## Workflow conditions are filter-builder conditions, and the log outlives the definition
Two decisions the whole of Phase 5 rests on:

**Conditions reuse the filter engine.** `workflows.conditions` stores the filter builder's own array shape and `Workflow::conditions()` hands it back as a `FilterGroup`. 5.3 evaluates it the way `LeadRuleMatcher` already does — hand the group to `FilterApplier` against `whereKey($record)` and ask `exists()`. Evaluating in SQL rather than PHP is what makes a workflow condition and a filter chip agree by construction, including NULL-aware negation, collation-driven case handling and date boundaries. Do not write a second, in-PHP comparator for workflow conditions.

**The execution log survives the thing it describes.** `workflow_runs.workflow_id` and `workflow_run_steps.workflow_action_id` are nullOnDelete, and each row keeps its own copy (`workflow_name`, `action_type`). Somebody asking why a record changed last month needs the log after the workflow is deleted. `SaveWorkflowAction` reconciles steps in place rather than delete-and-reinsert for the same reason: a new id per save would detach the whole history from the steps it describes.

Also: the column is `trigger_event`, not `trigger` — TRIGGER is a MySQL reserved word. `workflow_runs.dedupe_key` is a nullable unique index; it is how 5.2's "fires exactly once" is enforced rather than hoped for, since a check-then-insert is one two queue workers can both pass. Null means "no claim", so replayed and ad-hoc runs are unconstrained.
