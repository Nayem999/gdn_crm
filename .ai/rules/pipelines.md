---
paths:
  - 'app/Domain/Deals/Models/Pipeline.php'
  - 'app/Domain/Deals/Models/PipelineStage.php'
  - 'app/Domain/Deals/Actions/**'
  - 'app/Domain/Deals/DTOs/**'
  - 'app/Domain/Deals/Enums/StageOutcome.php'
  - 'app/Livewire/Deals/**'
  - 'resources/views/livewire/deals/**'
  - 'database/seeders/PipelinesSeeder.php'
---

# Pipelines and stages

## A deal stores a stage *key*, not a stage id
`deals.stage` is a string and `pipeline_stages.key` holds the same value, unique
within a pipeline (`unique(pipeline_id, key)`). This is what lets a stage be
renamed without rewriting every deal in it, and it is why 2.6's `DealStage`
values stay valid — `PipelinesSeeder` mirrors that enum exactly, so a deal
created before pipelines existed still resolves.

Keys are **permanent and derived from the name**, never taken from the browser.
`SavePipelineAction` honours a submitted key only when it already names a stage
on that pipeline; anything else is treated as a new stage and gets a key from
`PipelineStage::keyFrom()`. Reusing or reassigning a key would silently move
deals between stages.

`deals.pipeline_id` is nullable and null means the default pipeline —
`Deal::configuredStage()` resolves it at read time. Do not backfill it in a
migration; that would bake the seeded default's id into the schema history.

## Exactly one default, enforced in one action
"Exactly one row true, any number false" is not a unique constraint, and a
partial index is not portable. `SetDefaultPipelineAction` is therefore the only
thing that may write `is_default`, and `ensureOneExists()` repairs a database
that lost the flag. `Pipeline::default()` falls back to the first by position
rather than returning null, so a half-restored database still resolves.

## Won and lost are stages, not a flag on the deal
`StageOutcome` (open/won/lost) lives on the stage, so a board column and a closed
outcome are the same thing and cannot disagree. A closed stage's probability is
**fixed by its outcome** (100/0) in `StageData::fromArray()` whatever the form
sent — a typed 60% against "Closed won" would skew every forecast in a way
nobody would think to look for. `PipelineForm::updated()` mirrors that in the UI
the moment the outcome changes, so the field never shows a number that will not
be saved.

## Refusals live on the model, so the button and the request agree
`Pipeline::deletionBlocker()` returns the reason a pipeline cannot go (it is the
default, it is the last one, it still has deals) or null. `PipelinePolicy::delete`
and `DeletePipelineAction` both ask it, so a button is never offered for
something the action would refuse, and the list can explain *why* instead of
showing a control that 403s.

Dropping a stage that still has deals in it is refused the same way, inside the
transaction — the whole save rolls back rather than stranding deals in a stage
that no longer exists.

## Reordering takes ids from the browser, so it verifies them
`ReorderPipelinesAction` and `ReorderPipelineStagesAction` both intersect the
submitted order with what actually exists (stages scoped to the pipeline), drop
unknown entries, and append anything the browser did not mention *behind* what it
did. A stale page therefore cannot shuffle rows it never showed. Stage keys are
only unique per pipeline, so scoping the query by `pipeline_id` is load-bearing.

## Two verification notes
- A `wire:model` input carries no `value` attribute server-side; Livewire fills
  it on the client at boot. Assert form state with `assertSet`, not `assertSee`
  — `assertSee('Closed won')` fails on a field that renders correctly.
- `window.Livewire.find(document.querySelector('[wire:id]'))` in a browser check
  grabs the **topbar notification bell**, which is the first component on every
  page. Pick the component by a property it owns
  (`Livewire.all().find(c => …)` via its id) or you will be driving the wrong one
  and reading its 500s as yours.
