<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Domain\Timeline\Models\Document;
use App\Domain\Timeline\Models\Note;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

/**
 * @param  array<int, string>  $permissions
 */
function timelineUser(array $permissions = ['leads.view', 'timeline.view']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

/**
 * Someone who can do everything the timeline offers on a lead they own.
 */
function timelineAuthor(): User
{
    return timelineUser([
        'leads.view', 'leads.update',
        'timeline.view', 'timeline.create', 'timeline.update', 'timeline.delete',
    ]);
}

function timelineUserWithAccessLevel(DataAccessLevel $level, array $permissions): User
{
    $role = Role::query()->create([
        'name' => 'Timeline '.$level->value.' '.uniqid(),
        'guard_name' => Guard::getDefaultName(Role::class),
        'data_access_level' => $level->value,
    ]);

    $role->syncPermissions(PermissionResolver::models($permissions));

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

// -- A note is only ever as visible as its record ------------------------------

test('someone who cannot see the record cannot read what was written on it', function () {
    $owner = timelineAuthor();
    $lead = Lead::factory()->ownedBy($owner)->create();
    $note = Note::factory()->on($lead)->by($owner)->create();

    // Holds every timeline permission, but not leads.view.
    $stranger = timelineUser(['timeline.view', 'timeline.create', 'timeline.update', 'timeline.delete']);

    expect(Gate::forUser($stranger)->allows('view', $note))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('create', [Note::class, $lead]))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('delete', $note))->toBeFalse();
});

test('the record access level decides which timelines are readable', function () {
    $owner = timelineUserWithAccessLevel(DataAccessLevel::Own, ['leads.view', 'timeline.view']);
    $peer = timelineUserWithAccessLevel(DataAccessLevel::Own, ['leads.view', 'timeline.view']);

    $lead = Lead::factory()->ownedBy($owner)->create();
    $note = Note::factory()->on($lead)->by($owner)->create();

    // Both hold leads.view and timeline.view. Only one of them owns the lead,
    // and that is what decides — the note adds no visibility of its own.
    expect(Gate::forUser($owner)->allows('view', $note))->toBeTrue()
        ->and(Gate::forUser($peer)->allows('view', $note))->toBeFalse();
});

test('reading the timeline needs its own permission, not just the record', function () {
    $user = timelineUser(['leads.view']);
    $lead = Lead::factory()->ownedBy($user)->create();
    $note = Note::factory()->on($lead)->create();

    expect(Gate::forUser($user)->allows('view', $lead))->toBeTrue()
        ->and(Gate::forUser($user)->allows('view', $note))->toBeFalse();
});

// -- Writing -------------------------------------------------------------------

test('nobody edits a note they did not write', function () {
    $author = timelineAuthor();

    // Sees everything and holds every timeline permission, which is the point:
    // the refusal has to come from authorship, not from reach.
    $colleague = timelineUserWithAccessLevel(DataAccessLevel::All, [
        'leads.view', 'leads.update',
        'timeline.view', 'timeline.create', 'timeline.update', 'timeline.delete',
    ]);

    $lead = Lead::factory()->ownedBy($author)->create();
    $note = Note::factory()->on($lead)->by($author)->create();

    expect(Gate::forUser($colleague)->allows('view', $note))->toBeTrue()
        ->and(Gate::forUser($author)->allows('update', $note))->toBeTrue()
        ->and(Gate::forUser($colleague)->allows('update', $note))->toBeFalse();
});

test('the author can retract their own note and so can someone who may change the record', function () {
    $author = timelineUser(['leads.view', 'timeline.view', 'timeline.delete']);
    $lead = Lead::factory()->ownedBy($author)->create();
    $note = Note::factory()->on($lead)->by($author)->create();

    // Can change the lead itself, so may tidy its timeline.
    $manager = timelineUserWithAccessLevel(DataAccessLevel::All, [
        'leads.view', 'leads.update', 'timeline.view', 'timeline.delete',
    ]);

    // Sees the lead and holds timeline.delete, but cannot change the record
    // and did not write the note.
    $reader = timelineUserWithAccessLevel(DataAccessLevel::All, [
        'leads.view', 'timeline.view', 'timeline.delete',
    ]);

    expect(Gate::forUser($author)->allows('delete', $note))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('delete', $note))->toBeTrue()
        ->and(Gate::forUser($reader)->allows('delete', $note))->toBeFalse();
});

test('writing a note needs the create permission', function () {
    $lead = Lead::factory()->create();

    $writer = timelineUserWithAccessLevel(DataAccessLevel::All, ['leads.view', 'timeline.view', 'timeline.create']);
    $reader = timelineUserWithAccessLevel(DataAccessLevel::All, ['leads.view', 'timeline.view']);

    expect(Gate::forUser($writer)->allows('create', [Note::class, $lead]))->toBeTrue()
        ->and(Gate::forUser($reader)->allows('create', [Note::class, $lead]))->toBeFalse();
});

// -- Documents follow the same rules -------------------------------------------

test('a document is as reachable as the record it hangs off', function () {
    $owner = timelineUserWithAccessLevel(DataAccessLevel::Own, ['leads.view', 'timeline.view']);
    $peer = timelineUserWithAccessLevel(DataAccessLevel::Own, ['leads.view', 'timeline.view']);

    $lead = Lead::factory()->ownedBy($owner)->create();
    $document = Document::factory()->on($lead)->by($owner)->create();

    expect(Gate::forUser($owner)->allows('view', $document))->toBeTrue()
        ->and(Gate::forUser($peer)->allows('view', $document))->toBeFalse();
});

// -- The download route is the only door to the private disk -------------------

test('the file downloads for somebody allowed to have it', function () {
    $owner = timelineAuthor();
    $lead = Lead::factory()->ownedBy($owner)->create();
    $document = Document::factory()->on($lead)->by($owner)->withFile('contract.pdf')->create();

    $this->actingAs($owner)
        ->get(route('documents.download', $document))
        ->assertSuccessful();
});

test('a guessed document id downloads nothing for somebody who cannot see the record', function () {
    $owner = timelineUserWithAccessLevel(DataAccessLevel::Own, ['leads.view', 'timeline.view']);
    $peer = timelineUserWithAccessLevel(DataAccessLevel::Own, ['leads.view', 'timeline.view']);

    $lead = Lead::factory()->ownedBy($owner)->create();
    $document = Document::factory()->on($lead)->by($owner)->withFile()->create();

    $this->actingAs($peer)
        ->get(route('documents.download', $document))
        ->assertForbidden();
});

test('a document download is closed to a guest', function () {
    $document = Document::factory()->withFile()->create();

    $this->get(route('documents.download', $document))->assertRedirect(route('login'));
});

test('a document whose file has gone is a missing page, not a server error', function () {
    $owner = timelineAuthor();
    $lead = Lead::factory()->ownedBy($owner)->create();
    // No withFile(): the row exists, the bytes never did.
    $document = Document::factory()->on($lead)->by($owner)->create();

    $this->actingAs($owner)
        ->get(route('documents.download', $document))
        ->assertNotFound();
});

test('the stored file never sits on the public disk', function () {
    $owner = timelineAuthor();
    $lead = Lead::factory()->ownedBy($owner)->create();
    $document = Document::factory()->on($lead)->by($owner)->withFile('contract.pdf')->create();

    // A contract served from /storage would be readable by anyone who guessed
    // the path, so the collection is pinned to the private disk.
    expect($document->mediaFile()?->disk)->toBe('local');
});
