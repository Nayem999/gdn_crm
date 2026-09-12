---
paths:
  - 'app/Domain/Sales/**'
  - 'app/Domain/Products/Cpq/**'
  - 'app/Livewire/Sales/**'
  - 'database/migrations/*document_lines*.php'
  - 'database/migrations/*quote*.php'
  - 'database/migrations/*order*.php'
  - 'database/migrations/*invoice*.php'
  - 'resources/views/livewire/sales/**'
  - 'resources/views/pdf/**'
---

# Sales documents

## Sales documents are snapshots, and one calculator totals them all
**Every line is a snapshot.** Name, description, unit, price and tax rate are copied onto `document_lines`, never read through to the product. A quote sent in March still says what it said in March; `product_id` nulls out rather than restricting, so a retired product leaves a line that still prints. Conversions (quote→order→invoice) `replicate()` lines **with their stored figures** — what was agreed is what is owed, and recomputing would silently reprice when the catalogue moved.

**One arithmetic, in `LineCalculator`.** gross = qty × price → discount off the gross → tax on what is left. Discount before tax is not a preference: tax is owed on what was actually charged. Rounding is per line, and a document total is the **sum of the rounded lines** — the customer adds up the column they can see, and a total a penny away from its own rows is disputed. `SaveQuoteLinesAction` is the only writer of line figures and document totals.

`TaxMode` is per document, not per installation: the same company sends inclusive and exclusive. Inclusive extracts the tax by dividing by (1 + rate) and takes the tax as the **remainder**, so net + tax always equals the quoted figure.

Documents implement `SellingDocument` (with `HasDocumentLines`) — use `documentLines()`, not the magic `lines` property, when typed against the interface.

**Numbers come from `DocumentNumber`**, a counter row taken under a lock. Never `max(number) + 1`: two documents created in the same second both win that race, and invoices are expected to be gap-free.

**Status rules live in the enums, enforced by one action each.** An accepted quote never returns to draft; an invoice with payments cannot be cancelled; a draft order cannot be invoiced.

Invoice payment state is **derived** from `total` and `amount_paid`, never stored as a status — the two would disagree, meaning chasing a customer who paid. `amount_paid` is re-summed (not incremented) under a row lock by `RecordPaymentAction`, its only writer.

CPQ discount approval matches on **what was approved** (the stored summary, carrying the discount and total), not on timestamps: an edit in the same second as the decision is not "after" it at second granularity.
