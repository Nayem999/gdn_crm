---
paths:
  - 'app/Domain/Shared/Duplicates/**'
  - 'app/Domain/Shared/Actions/MergeRecordsAction.php'
  - 'app/Domain/Shared/Actions/SyncDuplicateKeysAction.php'
  - 'app/Domain/Shared/Concerns/MergesWithDuplicates.php'
  - 'app/Domain/Shared/Concerns/FindsDuplicates.php'
  - 'app/Domain/Shared/Concerns/WarnsAboutDuplicates.php'
  - 'app/Domain/*/[A-Z]*Duplicates.php'
  - 'app/Livewire/Duplicates/**'
  - 'resources/views/livewire/duplicates/**'
  - 'resources/views/components/duplicate-*'
---

# Duplicate detection and merging

## Matching is index-backed, not computed per query
`duplicate_keys` stores a normalised fingerprint per record per matched field.
Normalising inside the query instead — `LOWER(email)`, digits-only phone — is
unindexable, so a check on capture would scan the table. With the fingerprints
stored, finding duplicates is two queries whatever the table size: one over
`duplicate_keys` for candidate ids, one to load the ones the viewer may see.

Phase 8's ingestion gateway needs the same "match on email or phone" lookup.
Use this engine; do not write a second one.

## Normalisation lives in MatchStrategy and nowhere else
The value written and the value looked up must be produced by the same code.
`normalise()` returning **null** means "too weak to match on", which is not the
same as "no match" — a rule must never fall through to matching every record
with a blank field. That is why an email without an `@`, a phone under seven
digits, and anything under three characters of actual content all fingerprint to
nothing.

The length floor counts characters, not the separators between them: "A&B"
normalises to `a b`, which is two initials, so it is rejected.

## Fingerprints are kept up to date by model events, on purpose
`MergesWithDuplicates::bootMergesWithDuplicates()` syncs on `saved`, forgets on
`deleted`, re-syncs on `restored`. Hooking the write actions instead would work
today and leave task 2.7's import and the Phase 8 gateway free to forget — and a
stale fingerprint does not fail loudly, it silently stops finding duplicates.

Anything that writes a matched column with the query builder bypasses this. If
you add such a path, call `syncDuplicateKeys()` yourself.

## Scoring is per strategy, never per rule
A lead's `phone` and `mobile` are two rules on one strategy. Matching both is
still one telephone number's worth of evidence; counting it twice pushes a
shared switchboard into the "almost certainly the same" band. `DuplicateFinder`
therefore keys its hits by `MatchStrategy`, not by rule.

Weights are set so no single weak signal reaches "likely": a company name alone
is 40, below the 70 threshold, and only becomes convincing next to something
else.

## Everything a merge touches is declared by the module
`DuplicateSource` names the fields that may be matched, the fields that may be
written, and the tables whose rows may be moved. `MergeRecordsAction` intersects
the submitted values against `mergeableFields()`, so a tampered form cannot
write `status`, `is_primary` or `merged_into_id` — the same registry idea as
`PermissionCatalogue::only()`.

Leave a field out of `mergeableFields()` when an action owns it:
- `leads.status` belongs to `ChangeLeadStatusAction`.
- `contacts.is_primary` belongs to `SetPrimaryContactAction`; `afterMerge()`
  promotes the survivor through that action instead.

`afterMerge()` is where a module repairs what moving rows wholesale can break.
For accounts that is the hierarchy: re-pointing `parent_id` can make the
survivor its own parent, or leave an adopted subsidiary sitting above it. Both
loops are cut rather than guessed at — a subsidiary at the top level is visibly
wrong and easily fixed, a loop hangs every ancestor walk.

## A merged record is kept, and stays readable
The loser is stamped with `merged_into_id` and soft-deleted, never destroyed.
Its activity entries go on pointing at the record the events actually happened
to; re-pointing them at the survivor would rewrite who did what to which record,
and deleting the row would leave them dangling. That is what "merge preserves
history" has to mean.

Because the record is kept, its page must still open. That takes **two**
changes, and the second is easy to miss:

1. The show component loads `withTrashed()` and turns away a deletion that was
   not a merge.
2. The **route** needs `->withTrashed()` too — implicit model binding refuses a
   soft-deleted record before the component ever runs.

A test that passes the model into `Livewire::test()` bypasses binding entirely
and will not catch the second one. Assert through `route(...)` as well.

## Nothing merges itself
Confidence bands exist so an operator can see "exact" and still decide. There is
no automatic merge, and the capture-time warning is informative rather than
blocking: two people at one company really do share a switchboard number, and
only the person entering the record can tell.
