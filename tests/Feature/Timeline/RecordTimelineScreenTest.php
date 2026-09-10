<?php

use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Domain\Timeline\Models\Document;
use App\Domain\Timeline\Models\Note;
use App\Domain\Timeline\TimelineRegistry;
use App\Livewire\Timeline\RecordTimeline;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Someone who can work a record's timeline and see every record.
 */
function timelineOperator(): User
{
    // One line per module in TimelineRegistry: the policies ask the subject
    // record's own policy first, so a module added to the registry must be
    // viewable here or its dataset case 403s.
    return timelineUserWithAccessLevel(DataAccessLevel::All, [
        'leads.view', 'leads.update',
        'contacts.view', 'contacts.update',
        'accounts.view', 'accounts.update',
        'deals.view', 'deals.update',
        'timeline.view', 'timeline.create', 'timeline.update', 'timeline.delete',
    ]);
}

// -- Rendering -----------------------------------------------------------------

test('the timeline renders for every module in the registry', function (string $module) {
    $user = timelineOperator();
    $class = TimelineRegistry::modelClass($module);
    $subject = $class::factory()->create();

    Note::factory()->on($subject)->by($user)->create(['body' => 'something worth remembering']);
    Document::factory()->on($subject)->by($user)->create(['title' => 'signed-contract.pdf']);

    Livewire::actingAs($user)
        ->test(RecordTimeline::class, ['module' => $module, 'record' => $subject->id])
        ->assertSuccessful()
        ->assertSee('Timeline')
        ->assertSee('something worth remembering')
        ->assertSee('signed-contract.pdf');
})->with(fn () => TimelineRegistry::keys());

test('a record with nothing on it shows an empty state, never a blank panel', function () {
    $user = timelineOperator();
    $lead = Lead::factory()->create();
    $lead->activities()->delete();

    Livewire::actingAs($user)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->assertSee('Nothing on the timeline yet')
        ->assertSee('Write the first note');
});

test('an unlisted module is a missing page, not a class name', function () {
    Livewire::actingAs(timelineOperator())
        ->test(RecordTimeline::class, ['module' => 'users', 'record' => 1])
        ->assertNotFound();
});

test('a record the viewer cannot see is refused', function () {
    $owner = timelineUserWithAccessLevel(DataAccessLevel::Own, ['leads.view', 'timeline.view']);
    $peer = timelineUserWithAccessLevel(DataAccessLevel::Own, ['leads.view', 'timeline.view']);

    $lead = Lead::factory()->create(['owner_id' => $owner->id]);

    Livewire::actingAs($peer)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->assertForbidden();
});

test('the timeline appears on each record page', function (string $module, string $route) {
    $user = timelineOperator();
    $class = TimelineRegistry::modelClass($module);
    $subject = $class::factory()->create();

    Note::factory()->on($subject)->by($user)->create(['body' => 'shown on the record page']);

    $this->actingAs($user)
        ->get(route($route, $subject))
        ->assertSuccessful()
        ->assertSee('shown on the record page');
})->with(fn () => [
    ['leads', 'leads.show'],
    ['contacts', 'contacts.show'],
    ['accounts', 'accounts.show'],
]);

// -- Notes ---------------------------------------------------------------------

test('a note is written, appears immediately, and is attributed', function () {
    $user = timelineOperator();
    $lead = Lead::factory()->create();

    Livewire::actingAs($user)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->set('body', 'Spoke to them on Tuesday, calling back next week.')
        ->call('saveNote')
        ->assertHasNoErrors()
        ->assertSet('body', '')
        ->assertSee('Spoke to them on Tuesday')
        ->assertSee($user->name);

    expect($lead->notes()->count())->toBe(1)
        ->and($lead->notes()->first()->author_id)->toBe($user->id);
});

test('an empty note is refused', function () {
    $user = timelineOperator();
    $lead = Lead::factory()->create();

    Livewire::actingAs($user)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->set('body', ' ')
        ->call('saveNote')
        ->assertHasErrors(['body' => 'required']);

    expect($lead->notes()->count())->toBe(0);
});

test('a note longer than the column allows is refused', function () {
    $user = timelineOperator();
    $lead = Lead::factory()->create();

    Livewire::actingAs($user)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->set('body', str_repeat('a', 5001))
        ->call('saveNote')
        ->assertHasErrors(['body' => 'max']);
});

test('editing a note changes the words and not the author', function () {
    $user = timelineOperator();
    $lead = Lead::factory()->create();
    $note = Note::factory()->on($lead)->by($user)->create(['body' => 'first thoughts']);

    Livewire::actingAs($user)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->call('editNote', $note->id)
        ->assertSet('body', 'first thoughts')
        ->assertSet('editingNoteId', $note->id)
        ->set('body', 'second thoughts')
        ->call('saveNote')
        ->assertSet('editingNoteId', null)
        ->assertSee('second thoughts');

    expect($note->fresh()->body)->toBe('second thoughts')
        ->and($note->fresh()->author_id)->toBe($user->id);
});

test('a note belonging to another record cannot be edited through this one', function () {
    $user = timelineOperator();
    $lead = Lead::factory()->create();
    $other = Lead::factory()->create();
    $note = Note::factory()->on($other)->by($user)->create();

    // The id is real and the note is the viewer's own — what refuses it is that
    // it does not hang off the record this component is showing.
    Livewire::actingAs($user)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->call('editNote', $note->id)
        ->assertNotFound();
});

test('somebody else than the author cannot edit a note through the screen', function () {
    $author = timelineOperator();
    $colleague = timelineOperator();

    $lead = Lead::factory()->create();
    $note = Note::factory()->on($lead)->by($author)->create();

    Livewire::actingAs($colleague)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->call('editNote', $note->id)
        ->assertForbidden();
});

test('a note is removed and the deletion is on the audit trail', function () {
    $user = timelineOperator();
    $lead = Lead::factory()->create();
    $note = Note::factory()->on($lead)->by($user)->create(['body' => 'written in error']);

    Livewire::actingAs($user)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->call('deleteNote', $note->id)
        ->assertDontSee('written in error');

    expect($lead->notes()->count())->toBe(0)
        // The row is gone, but what was removed and by whom is not.
        ->and(Activity::query()
            ->where('subject_type', (new Note)->getMorphClass())
            ->where('event', 'deleted')
            ->exists())->toBeTrue();
});

// -- Documents -----------------------------------------------------------------

test('a document is attached, stored privately and listed', function () {
    $user = timelineOperator();
    $lead = Lead::factory()->create();

    Livewire::actingAs($user)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->call('startAttaching')
        ->set('upload', UploadedFile::fake()->create('signed contract.pdf', 12, 'application/pdf'))
        ->set('documentTitle', 'Signed contract')
        ->set('documentDescription', 'Countersigned on the 3rd')
        ->call('attachDocument')
        ->assertHasNoErrors()
        ->assertSet('attaching', false)
        ->assertSee('Signed contract');

    $document = $lead->documents()->first();

    expect($document->title)->toBe('Signed contract')
        ->and($document->uploaded_by_id)->toBe($user->id)
        ->and($document->mediaFile()?->disk)->toBe('local')
        // The client's own file name never decides a path.
        ->and($document->fileName())->toBe('signed-contract.pdf');
});

test('a document with no title falls back to the file name', function () {
    $user = timelineOperator();
    $lead = Lead::factory()->create();

    Livewire::actingAs($user)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->call('startAttaching')
        ->set('upload', UploadedFile::fake()->create('quote.pdf', 4, 'application/pdf'))
        ->call('attachDocument')
        ->assertHasNoErrors();

    expect($lead->documents()->first()->title)->toBe('quote.pdf');
});

test('attaching without a file is refused', function () {
    $user = timelineOperator();
    $lead = Lead::factory()->create();

    Livewire::actingAs($user)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->call('startAttaching')
        ->call('attachDocument')
        ->assertHasErrors(['upload' => 'required']);

    expect($lead->documents()->count())->toBe(0);
});

test('a file over the cap is refused by this screen, not by the framework', function () {
    $user = timelineOperator();
    $lead = Lead::factory()->create();

    // Between our 10MB cap and Livewire's own 12MB one, so the rejection has to
    // come from the component's rules — otherwise the message talks about a
    // temporary file rather than about a document.
    Livewire::actingAs($user)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->call('startAttaching')
        ->set('upload', UploadedFile::fake()->create('huge.pdf', 11000, 'application/pdf'))
        ->call('attachDocument')
        ->assertHasErrors(['upload' => 'max']);

    expect($lead->documents()->count())->toBe(0);
});

test('removing a document takes its file with it', function () {
    $user = timelineOperator();
    $lead = Lead::factory()->create();
    $document = Document::factory()->on($lead)->by($user)->withFile()->create();

    expect($document->mediaFile())->not->toBeNull();

    Livewire::actingAs($user)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->call('deleteDocument', $document->id);

    expect($lead->documents()->count())->toBe(0)
        // A "deleted" contract still sitting on disk with nothing pointing at
        // it is worse than either outcome.
        ->and(Media::query()
            ->where('model_type', (new Document)->getMorphClass())
            ->where('model_id', $document->id)
            ->exists())->toBeFalse();
});

test('a document belonging to another record cannot be removed through this one', function () {
    $user = timelineOperator();
    $lead = Lead::factory()->create();
    $other = Lead::factory()->create();
    $document = Document::factory()->on($other)->by($user)->create();

    Livewire::actingAs($user)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->call('deleteDocument', $document->id)
        ->assertNotFound();

    expect($other->documents()->count())->toBe(1);
});

// -- Filtering and paging ------------------------------------------------------

test('the strand filter narrows what is shown', function () {
    $user = timelineOperator();
    $lead = Lead::factory()->create();

    Note::factory()->on($lead)->by($user)->create(['body' => 'a written note']);
    Document::factory()->on($lead)->by($user)->create(['title' => 'an attached file']);

    Livewire::actingAs($user)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->assertSee('a written note')
        ->assertSee('an attached file')
        ->call('toggleKind', 'note')
        ->assertSet('kinds', ['note'])
        ->assertSee('a written note')
        ->assertDontSee('an attached file')
        ->call('showAllKinds')
        ->assertSet('kinds', [])
        ->assertSee('an attached file');
});

test('a filter value the enum does not know is ignored', function () {
    $user = timelineOperator();
    $lead = Lead::factory()->create();

    Livewire::actingAs($user)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->call('toggleKind', 'notes; drop table notes')
        ->assertSuccessful()
        ->assertSet('kinds', []);
});

test('load more reaches entries the first page did not show', function () {
    $user = timelineOperator();
    $lead = Lead::factory()->create();
    $lead->activities()->delete();

    foreach (range(1, 25) as $index) {
        Note::factory()->on($lead)->by($user)->create(['body' => 'note number '.$index]);
    }

    Livewire::actingAs($user)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->assertSee('Load more')
        ->assertDontSee('note number 1<')
        ->call('loadMore')
        ->assertSet('visible', 40)
        ->assertSee('note number 1')
        ->assertDontSee('Load more');
});

// -- A merged record keeps its timeline ----------------------------------------

test('a merged record still shows the history that is the reason it was kept', function () {
    $user = timelineOperator();
    $lead = Lead::factory()->create();
    Note::factory()->on($lead)->by($user)->create(['body' => 'said before the merge']);

    $survivor = Lead::factory()->create();
    $lead->forceFill(['merged_into_id' => $survivor->id, 'merged_at' => now()])->save();
    $lead->delete();

    Livewire::actingAs($user)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->assertSuccessful()
        ->assertSee('said before the merge');
});

// -- Read-only viewers ---------------------------------------------------------

test('somebody who may only read is offered no composer and no uploader', function () {
    $reader = timelineUserWithAccessLevel(DataAccessLevel::All, ['leads.view', 'timeline.view']);
    $lead = Lead::factory()->create();
    Note::factory()->on($lead)->create(['body' => 'readable all the same']);

    Livewire::actingAs($reader)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->assertSee('readable all the same')
        ->assertDontSee('Write a note about this record')
        ->assertDontSee('Attach');
});

test('a reader cannot write a note by calling the action directly', function () {
    $reader = timelineUserWithAccessLevel(DataAccessLevel::All, ['leads.view', 'timeline.view']);
    $lead = Lead::factory()->create();

    Livewire::actingAs($reader)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->set('body', 'sneaking this in')
        ->call('saveNote')
        ->assertForbidden();

    expect($lead->notes()->count())->toBe(0);
});

test('a reader cannot attach a document by calling the action directly', function () {
    $reader = timelineUserWithAccessLevel(DataAccessLevel::All, ['leads.view', 'timeline.view']);
    $lead = Lead::factory()->create();

    Livewire::actingAs($reader)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->set('upload', UploadedFile::fake()->create('sneaky.pdf', 4, 'application/pdf'))
        ->call('attachDocument')
        ->assertForbidden();

    expect($lead->documents()->count())->toBe(0);
});

// -- No plain dropdowns --------------------------------------------------------

test('the timeline ships no plain select', function () {
    $user = timelineOperator();
    $lead = Lead::factory()->create();

    $html = Livewire::actingAs($user)
        ->test(RecordTimeline::class, ['module' => 'leads', 'record' => $lead->id])
        ->html();

    // The filter is chips, not a dropdown. Any select here would have to be an
    // <x-select>, and there are none.
    expect(substr_count($html, '<select'))->toBe(0);
});
