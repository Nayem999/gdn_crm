<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Leads\Models\Lead;
use App\Domain\Timeline\Enums\TimelineEntryKind;
use App\Domain\Timeline\Models\Document;
use App\Domain\Timeline\Models\Note;
use App\Domain\Timeline\TimelineBuilder;
use App\Domain\Timeline\TimelinePage;
use App\Domain\Timeline\TimelineRegistry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

function timeline(Model $subject, int $limit = 20, ?array $kinds = null): TimelinePage
{
    return app(TimelineBuilder::class)->for($subject, $limit, $kinds);
}

/**
 * @return array<int, string>
 */
function timelineKinds(TimelinePage $page): array
{
    return array_map(fn ($entry) => $entry->kind->value, $page->entries);
}

// -- Ordering ------------------------------------------------------------------

test('entries come back newest first across all three strands', function () {
    $lead = Lead::factory()->create();
    // The lead's own creation writes a history entry, so clear the slate and
    // place each strand at a known moment instead.
    $lead->activities()->delete();

    Carbon::setTestNow('2026-05-01 09:00:00');
    Note::factory()->on($lead)->create(['body' => 'oldest note']);

    Carbon::setTestNow('2026-05-02 09:00:00');
    Document::factory()->on($lead)->create(['title' => 'middle document']);

    Carbon::setTestNow('2026-05-03 09:00:00');
    $lead->forceFill(['company_name' => 'Renamed Ltd'])->save();

    Carbon::setTestNow('2026-05-04 09:00:00');
    Note::factory()->on($lead)->create(['body' => 'newest note']);

    Carbon::setTestNow();

    $page = timeline($lead);

    expect($page->count())->toBe(4)
        ->and($page->entries[0]->body)->toBe('newest note')
        ->and($page->entries[1]->kind)->toBe(TimelineEntryKind::History)
        ->and($page->entries[2]->document?->title)->toBe('middle document')
        ->and($page->entries[3]->body)->toBe('oldest note');
});

test('entries written in the same second still come back in a stable total order', function () {
    $lead = Lead::factory()->create();
    $lead->activities()->delete();

    Carbon::setTestNow('2026-05-01 09:00:00');

    foreach (range(1, 5) as $index) {
        Note::factory()->on($lead)->create(['body' => 'note '.$index]);
    }

    Carbon::setTestNow();

    $first = array_map(fn ($entry) => $entry->id, timeline($lead)->entries);
    $second = array_map(fn ($entry) => $entry->id, timeline($lead)->entries);

    // One strand, one second: the id decides, and it means something here.
    // Without a total order the result would be whatever the engine felt like,
    // and paging would repeat or skip rows.
    expect($first)->toBe($second)
        ->and($first)->toBe(array_reverse(collect($first)->sort()->values()->all()));
});

test('in the same second what a person did sits above the bookkeeping', function () {
    Carbon::setTestNow('2026-05-01 09:00:00');

    $lead = Lead::factory()->create();

    // All three in one second, which is the ordinary case: writing a note also
    // touches the record. Ids come from three different sequences, so they
    // cannot decide this — an activity id being larger says nothing about
    // which happened first, and ordering on it put the note underneath.
    $lead->forceFill(['company_name' => 'Renamed Ltd'])->save();
    Document::factory()->on($lead)->create(['title' => 'attached.pdf']);
    Note::factory()->on($lead)->create(['body' => 'written just now']);

    Carbon::setTestNow();

    expect(timelineKinds(timeline($lead)))->toBe(['note', 'document', 'history', 'history']);
});

test('a note keeps its place when it is edited', function () {
    $lead = Lead::factory()->create();
    $lead->activities()->delete();

    Carbon::setTestNow('2026-05-01 09:00:00');
    $note = Note::factory()->on($lead)->create(['body' => 'written first']);

    Carbon::setTestNow('2026-05-02 09:00:00');
    Note::factory()->on($lead)->create(['body' => 'written second']);

    Carbon::setTestNow('2026-05-03 09:00:00');
    $note->forceFill(['body' => 'edited last'])->save();

    Carbon::setTestNow();

    // The moment a note belongs to is when it was written, not when it was
    // last touched — otherwise a typo fix reorders the record's history.
    expect(timeline($lead)->entries[0]->body)->toBe('written second');
});

// -- Paging --------------------------------------------------------------------

test('the page reports more behind it and load more reaches the rest', function () {
    $lead = Lead::factory()->create();
    $lead->activities()->delete();

    Note::factory()->count(25)->on($lead)->create();

    $first = timeline($lead, 20);

    expect($first->count())->toBe(20)
        ->and($first->hasMore)->toBeTrue();

    $second = timeline($lead, 40);

    expect($second->count())->toBe(25)
        ->and($second->hasMore)->toBeFalse();
});

test('paging never repeats or skips an entry', function () {
    $lead = Lead::factory()->create();
    $lead->activities()->delete();

    Note::factory()->count(12)->on($lead)->create();
    Document::factory()->count(12)->on($lead)->create();

    $keys = array_map(
        fn ($entry) => $entry->kind->value.':'.$entry->id,
        timeline($lead, 40)->entries
    );

    expect($keys)->toHaveCount(24)
        ->and(array_unique($keys))->toHaveCount(24);
});

// -- Filtering -----------------------------------------------------------------

test('a filter narrows the timeline to the strands asked for', function () {
    $lead = Lead::factory()->create();
    Note::factory()->on($lead)->create();
    Document::factory()->on($lead)->create();

    expect(timelineKinds(timeline($lead, 20, [TimelineEntryKind::Note])))->toBe(['note'])
        ->and(timelineKinds(timeline($lead, 20, [TimelineEntryKind::Document])))->toBe(['document'])
        ->and(timelineKinds(timeline($lead, 20, [TimelineEntryKind::History])))->toBe(['history']);
});

test('no filter means every strand', function () {
    $lead = Lead::factory()->create();
    Note::factory()->on($lead)->create();
    Document::factory()->on($lead)->create();

    expect(timelineKinds(timeline($lead)))->toContain('note', 'document', 'history');
});

test('the counts describe the whole record, not the page', function () {
    $lead = Lead::factory()->create();
    Note::factory()->count(3)->on($lead)->create();
    Document::factory()->count(2)->on($lead)->create();

    $page = timeline($lead, 1);

    expect($page->count())->toBe(1)
        ->and($page->noteCount)->toBe(3)
        ->and($page->documentCount)->toBe(2);
});

// -- Polymorphic relations -----------------------------------------------------

test('a record only ever shows its own entries', function () {
    $lead = Lead::factory()->create();
    $other = Lead::factory()->create();

    Note::factory()->on($lead)->create(['body' => 'about this lead']);
    Note::factory()->on($other)->create(['body' => 'about the other lead']);

    $bodies = array_map(fn ($entry) => $entry->body, timeline($lead, 20, [TimelineEntryKind::Note])->entries);

    expect($bodies)->toBe(['about this lead']);
});

test('two records of different modules sharing an id do not share a timeline', function () {
    // The pairing that a morph type exists to keep apart: same id, different
    // table. Matching on id alone would show each the other's notes.
    $lead = Lead::factory()->create();
    $contact = Contact::factory()->create();

    // Line them up on the same key, whatever the auto-increments happen to be.
    $contact->forceFill(['id' => $lead->id + 10_000])->save();
    $lead->forceFill(['id' => $contact->id])->save();
    $lead = $lead->fresh();

    Note::factory()->on($lead)->create(['body' => 'lead note']);
    Note::factory()->on($contact)->create(['body' => 'contact note']);

    expect(array_map(fn ($e) => $e->body, timeline($lead, 20, [TimelineEntryKind::Note])->entries))->toBe(['lead note'])
        ->and(array_map(fn ($e) => $e->body, timeline($contact, 20, [TimelineEntryKind::Note])->entries))->toBe(['contact note']);
});

test('every module in the registry carries notes, documents and history', function (string $module) {
    $class = TimelineRegistry::modelClass($module);

    /** @var Model $subject */
    $subject = $class::factory()->create();

    Note::factory()->on($subject)->create(['body' => 'a note']);
    Document::factory()->on($subject)->create(['title' => 'a document']);

    expect(timelineKinds(timeline($subject)))->toContain('note', 'document', 'history')
        ->and($subject->notes()->count())->toBe(1)
        ->and($subject->documents()->count())->toBe(1);
})->with(fn () => TimelineRegistry::keys());

test('the registry recognises a record by its class, never by a name from a request', function () {
    expect(TimelineRegistry::keyFor(new Lead))->toBe('leads')
        ->and(TimelineRegistry::keyFor(new Contact))->toBe('contacts')
        ->and(TimelineRegistry::keyFor(new Account))->toBe('accounts')
        ->and(TimelineRegistry::keyFor(new User))->toBeNull()
        ->and(TimelineRegistry::has('users'))->toBeFalse()
        ->and(TimelineRegistry::modelClass('users'))->toBeNull();
});

// -- Missing people ------------------------------------------------------------

test('an entry whose author has gone still renders', function () {
    $lead = Lead::factory()->create();
    $note = Note::factory()->on($lead)->authorless()->create(['body' => 'left behind']);

    $entry = timeline($lead, 20, [TimelineEntryKind::Note])->entries[0];

    expect($entry->actor)->toBeNull()
        ->and($entry->title)->toContain('Somebody who has since left')
        ->and($entry->body)->toBe($note->body);
});

test('removing a user leaves their notes on the record', function () {
    $author = User::factory()->create();
    $lead = Lead::factory()->create();

    Note::factory()->on($lead)->by($author)->create(['body' => 'still here']);

    $author->forceDelete();

    expect(timeline($lead, 20, [TimelineEntryKind::Note])->entries[0]->body)->toBe('still here');
});
