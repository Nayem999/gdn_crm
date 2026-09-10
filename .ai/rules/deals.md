---
paths:
  - 'app/Domain/Deals/Models/Deal.php'
  - 'app/Domain/Deals/Actions/{CreateDeal,UpdateDeal,DeleteDeal,MoveDealStage,CloseDeal}Action.php'
  - 'app/Domain/Deals/DTOs/DealData.php'
  - 'app/Domain/Deals/DealFields.php'
  - 'app/Domain/Deals/DealExportSource.php'
  - 'app/Domain/Deals/Enums/DealCloseReason.php'
  - 'app/Livewire/Deals/Deal*.php'
  - 'resources/views/livewire/deals/deal*.blade.php'
---

# Deals

Same list-screen shape as Accounts — see .ai/rules/accounts.md, which applies
unchanged. Stages and pipelines are .ai/rules/pipelines.md. What is different
here is that a deal's *position* is owned by one action, and its outcome is
derived rather than stored.

## MoveDealStageAction is the only writer of `stage`
Enforced by leaving stage out of every other path rather than by checking it in
each of them:

- `stage` is **not in `Deal::$fillable`**, and `DealData` has no stage field, so
  no form or payload can carry one.
- `DealForm` has no stage control. A test asserts the rendered form has none.
- `CreateDealAction` and `ConvertLeadAction` therefore write the opening stage
  with `forceFill`, not `create()` — `create()` silently drops it. Both take it
  from `Pipeline::openingStage()` so they cannot disagree about where a deal
  starts. **This bit twice during 3.2**: a stage handed to `Deal::create()`
  vanishes without error and the record quietly keeps the `$attributes` default.
- `DealsIndex::moveCard()` and `DealShow::moveTo()` both go through the action.
- Factories bypass `$fillable` (Laravel wraps `Factory::make` in
  `Model::unguarded`), so factory stage states keep working.

A stage key that is not on the deal's pipeline is **refused**, not written: the
board would show the deal in no column at all, which is how a record goes
missing.

## The outcome is derived from the stage, never stored
`Deal::outcome()` asks the configured stage, falling back to the `DealStage`
enum for a deal on no pipeline. There is deliberately no `is_won` column — a
flag could disagree with the board, and the board is what people look at.

`closed_at` *is* stored, stamped when a deal first reaches a closing stage.
Re-clicking the stage it is already in is a no-op so the stamp is not pushed
forward, and moving back to an open stage clears the stamp, the reason and the
notes — a live deal that still says why it was lost reads as a closed one.

`Deal::closingStageKeys()` reads the configured outcomes so a pipeline whose
winning stage is called "signed" is counted. It collects across every pipeline,
which is a deliberate simplification documented on the method.

## A close reason must match the outcome
`DealCloseReason` declares its own outcome, and `CloseDealAction` refuses a
mismatch. Recording a won deal as "lost to a competitor" would corrupt the
Phase 10 win/loss report in a way nobody would spot until the quarter was over.
The reasons are a fixed enum, not configurable: a free-text list turns that
report into a thousand buckets of one. Customer-specific nuance goes in
`close_notes`.

Moving to a closing stage from `DealShow` opens the reason form *before* moving
— the reason is the one thing that cannot be filled in later without somebody
remembering. A board drag does move it, and says the reason is still missing.

`deals.close` is its own permission, separate from `deals.update`.

## Changing pipeline is refused unless the target has the current stage
`UpdateDealAction` will not translate stages between pipelines. Guessing which
Renewals stage corresponds to "Negotiation" is a business decision, and getting
it wrong silently reprices the forecast.

## weighted_value is a display column, and the totals join for it
It is `value × the stage's probability`, and the probability lives on
`pipeline_stages`. So:

- `DealFields::sortColumn()` returns null for it — sorting in SQL would need a
  join this query does not carry, and would then disagree with the figure on
  screen for a deal whose pipeline has no matching stage.
- `DealsIndex::totals()` runs its own aggregate over the whole filtered set with
  a `leftJoin` on `(pipeline_id, key)` and `COALESCE(probability, 0)`, so a deal
  whose stage matches nothing counts at zero rather than dropping out of the
  sum. Summing the page would describe the page, not the data.
- The export writes it as a **number**, not a formatted string: a spreadsheet
  column of "£600.00" cannot be summed.

## Search stays on this table, because the kit's does
`WithDataView` builds the list's search from `dataViewSearchColumns()` — plain
columns on the model's own table, no relations. `Deal::scopeSearch()` therefore
reads that same declaration and does **not** reach into the account, even though
searching a deal by its organisation would be useful. A relation in the model
scope would make a queued export match rows the list never showed, which is the
drift .ai/rules/accounts.md exists to prevent. A test asserts the two return
identical ids.

## The board is per-pipeline, and the control says which
`dataViewKanbanColumns()` comes from the selected pipeline's stages, so kanban
mode scopes the query to one pipeline — a board mixing two pipelines' stages
would put a deal in a column that does not apply to it.

The pipeline selector's **placeholder** names the board's pipeline in kanban
mode; its value is left empty on purpose. Setting the value server-side does not
work: Tom Select sits behind `wire:ignore` and keeps its own selection, so the
value lands in the native `<select>` and not in the control on screen — even
with a moving `wire:key`, which was tried and verified not to help. Browser
verification caught the original version reading "All pipelines" while the board
showed one.
