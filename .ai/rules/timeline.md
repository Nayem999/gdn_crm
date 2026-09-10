---
paths:
  - 'app/Domain/Timeline/**'
  - 'app/Livewire/Timeline/**'
  - 'app/Http/Controllers/DownloadDocument.php'
  - 'resources/views/livewire/timeline/**'
  - 'resources/views/components/timeline-entry.blade.php'
---

# Timeline

A record's timeline is three strands merged into one list: notes (`notes`),
documents (`documents`) and history (spatie activity entries whose subject is
the record). One Livewire component, `Timeline\RecordTimeline`, serves every
module in `TimelineRegistry`.

## A note or document carries no authorization of its own
`NotePolicy` and `DocumentPolicy` ask the **subject record's** policy before
answering anything — `Gate::forUser($user)->allows('view', $note->notable)`. That
is what stops the timeline becoming a way round a module's permission or its
data access level: someone who cannot see a lead cannot read what was written on
it, however many `timeline.*` permissions they hold. Both halves are required —
the `timeline.*` permission *and* the record.

Editing is author-only (`timeline.update` plus `author_id === $user->id`): an
entry in a record's history that a third party can rewrite is not history.
Deleting is the author, or somebody who may `update` the subject record.

## Document bytes are on the private disk, reachable only through the route
`Document::registerMediaCollections()` pins the `file` collection to the `local`
disk (`storage/app/private`). A contract served from `/storage` would be readable
by anyone who guessed the path. The **only** door is
`GET /documents/{document}/download` → `DownloadDocument`, which calls
`$this->authorize('view', $document)` before a byte moves. Never call
`getUrl()` on a document's media in a view.

`UploadDocumentAction` rebuilds the stored file name from a slug of the original.
The client-supplied name is attacker-controlled and has no business deciding a
path.

The component's own `max:10240` must stay **below** Livewire's temporary-upload
cap (12MB, `config/livewire.php`), or the rejection comes from the framework with
a message about a temporary file rather than from the screen about a document.

## Ordering is a total order, not just a sort
Three tables cannot be ordered by one `ORDER BY`, so `TimelineBuilder` reads each
strand with `LIMIT n+1` (newest first, `created_at` then `id`) and merges in
memory on `TimelineEntry::sortKey()` — timestamp, then zero-padded id, then kind.
All three parts are load-bearing: entries written in the same second would
otherwise come back in whatever order the engine chose, and "load more" would
repeat or skip rows. The `n+1` read is also how `hasMore` is answered without a
second count query.

A note's moment is `created_at`, never `updated_at`. Fixing a typo must not
reorder a record's history.

## Merging moves the timeline but not the audit trail
Notes and documents follow the survivor; audit entries stay on the record the
events happened to (see .ai/rules/duplicates.md). A note written on a duplicate
is about the same person; an audit entry is about that row.

`DuplicateSource::inboundRelations()` grew a `where` key for this. A polymorphic
table is addressed by type **and** id, so moving on `notable_id` alone would drag
a contact's notes onto an account that happens to share its id — there is a test
that lines two records up on the same key to prove it does not.

Build those rows with `TimelineRegistry::inboundRelations($morphClass)` rather
than writing them out per module, so a fourth strand is picked up everywhere at
once.

## Adding a module to the timeline
1. `use App\Domain\Timeline\Concerns\HasTimeline` on the model (it must already
   use `RecordsActivity`, which provides the `activities()` relation).
2. Add it to `TimelineRegistry::subjects()`.
3. Add `TimelineRegistry::inboundRelations()` to its `DuplicateSource`.
4. Drop `<livewire:timeline.record-timeline :module="..." :record="...->id" />`
   into its show view, keyed on the record id.

The dataset tests in tests/Feature/Timeline walk the registry, so a module added
to it is covered without adding cases by hand — but add the module's own `view`
permission to `timelineOperator()` in RecordTimelineScreenTest at the same time.
Both policies ask the subject's policy first, so without it the new dataset case
403s. That is the test being right, not in the way.

## ActivityPresenter is shared with the audit viewer
`changes()`, `value()` and `color()` live in `App\Domain\Audit\ActivityPresenter`.
`ActivityLogIndex` delegates to it, and so does `TimelineEntry::fromActivity()`.
Two screens rendering the same row must not be able to disagree about it.
