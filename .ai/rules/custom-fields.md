---
paths:
  - 'app/Domain/CustomFields/**'
  - 'app/Livewire/CustomFields/**'
  - 'resources/views/livewire/custom-fields/**'
---

# Custom Fields

## Typed value columns, not one serialised blob — and keys are permanent
`custom_field_values` has a real column per type (`value_string`, `value_number`, `value_date`, `value_boolean`, `value_json`, `value_lookup_id`) and `CustomFieldType::column()` says which. The single-`value` EAV shape is the trap this avoids: a number stored as text sorts "10" before "9", a date cannot be range-compared, and neither can carry a useful index — and 4.2 puts custom fields in the filter builder, where the architecture rules ask for an index on every filterable field.

Exactly one column is written per row. `CustomFieldValue::attributesFor()` nulls the rest, because a row that kept a stale `value_string` after its field changed type would still match a text filter, and the record would appear in a list that does not describe it.

A cleared answer **deletes its row** rather than storing nulls — empty rows would make every "is not empty" filter wrong. A checkbox is the exception: `false` is a real answer.

Keys are derived from the label and permanent — both the field's `key` and each option's. Saved filters, import mappings and export columns all refer to a field by key, so reassigning one silently repoints every one of them. `SaveCustomFieldAction` is the only writer of `key` and `module`, and it writes each exactly once: a field cannot be moved between modules, because its values hang off records of the old module's type.

Deleting a field cascades its answers; `is_active` is the reversible option and is what the UI offers first.

## Custom fields reach the four places through {Module}Fields, not four call sites
A new custom field has to appear on the list, in the column manager, in the filter builder and in an export without anybody wiring it up four times. It does so through two merge points and two central ones:

- `{Module}Fields::columns()` and `::filters()` wrap their literals in `CustomFieldColumns::mergeColumns/mergeFilters`. That class is already the one thing the screen *and* the export read, which is exactly why a field cannot end up filterable on screen and absent from a queued export.
- `WithDataView::cellFor()` renders a `cf_*` cell, and `DataViewExport::map()` fills a `cf_*` export cell. Both are in the kit, so no module's `cellFor`/`exportRow` knows custom fields exist.
- Both eager-load `customFieldValues`, or a page of fifty rows is fifty queries.

Column keys are prefixed `cf_` so a field keyed `status` cannot shadow the module's own status column. Custom columns are `hiddenByDefault` and **not sortable**: the value is in another table and the kit's sort carries no join, and a sort control that silently ordered by nothing is worse than none.

Search deliberately does **not** include custom fields — `dataViewSearchColumns()` stays on the model's own table, for the reason in .ai/rules/accounts.md.
