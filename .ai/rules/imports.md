---
paths:
  - 'app/Domain/Shared/Imports/**'
  - 'app/Domain/Shared/Actions/RunImportAction.php'
  - 'app/Domain/Shared/Models/ImportRun.php'
  - 'app/Domain/*/[A-Z]*ImportSource.php'
  - 'app/Livewire/Imports/**'
  - 'app/Jobs/RunImport.php'
  - 'resources/views/livewire/imports/**'
---

# Importing

## Nothing is written until somebody has seen what will happen
The preview validates every row against the module's rules and reports the
counts before a single record is created. An import that reports its mistakes
afterwards is a mess to undo, and undoing it is not a feature this has.

## One row, one transaction — the opposite of conversion
`RunImportAction` wraps each row, not the file. Two thousand rows where row
1,700 is malformed should land the other 1,999. Lead conversion makes the
opposite choice for the opposite reason: there the three records are one thing,
here every row stands alone.

A module's `create()` can still write several rows — a contact and its account's
primary flag — which is why the row is wrapped at all.

## Records are created through the module's own action
`ImportSource::create()` calls `CreateLeadAction`, `CreateContactAction`,
`CreateAccountAction`. An imported record therefore obeys every rule a typed-in
one does: owner defaults, lead status, the one-primary-per-account rule,
duplicate fingerprints. Never insert straight into the table from an importer.

A field an action owns stays out of `fields()` for the same reason `merge`
leaves it out of `mergeableFields()`: `leads.status`, `contacts.is_primary`.

## Required means "required if you said you would supply it"
`rulesFor()` only returns rules for fields the mapping actually covers. A module
cannot demand a column the file does not have; what it *can* demand is that the
operator maps its required fields, and `missingRequired()` is what stops the
import until they do.

## Rows are numbered the way the file is
Headings are row 1 and the first record is row 2, so an error report saying
"row 7" points at the line somebody can open and look at. Getting this off by
one makes every report subtly wrong, which is worse than having no report.

## CSV is streamed; spreadsheets are capped
`fgetcsv` a line at a time, because "large file queues" is not much use if the
reader loads the whole file into memory first. XLSX has no streaming equivalent
here, so it is read whole and refused above `SPREADSHEET_ROW_LIMIT` with a
message saying to use CSV — refusing beats silently truncating somebody's data.

## Two Blade traps this screen hit
Both cost a compile error with a message that points nowhere near the cause
("syntax error, unexpected token endif").

- **Never put `@if` inside an element's attribute list.** Livewire wraps every
  `@if` in `<!--[if BLOCK]-->` HTML comments; inside a tag that produces markup
  the compiler cannot parse. Put the conditional attribute on its own element.
- **`@disabled` / `@checked` / `@selected` do not work inside an `<x-component>`
  tag.** The directive compiles to raw PHP, and the component compiler then
  skips the tag, leaving a stray `endif` at file scope. Use the bound prop:
  `:disabled="$expression"`.

## A component action must not be called `upload`
`$wire.upload()` is Livewire's own file-upload primitive, so a component method
of that name is shadowed on the client: `wire:submit` still reaches the server
method, but anything calling it from Alpine or JS silently hits Livewire's
instead. The step-one action is `readFile()` for that reason.
