<?php

namespace App\Domain\Timeline\Policies;

use App\Domain\Timeline\Models\Note;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * A note is only ever as visible as the record it hangs off.
 *
 * Every question here asks the subject's own policy first, so a note can never
 * become a way past a module's permission or its access level: someone who
 * cannot see a lead cannot read what was written on it, whatever the timeline
 * permissions say.
 */
class NotePolicy
{
    public function view(User $user, Note $note): bool
    {
        return $user->can('timeline.view') && $this->canViewSubject($user, $note->notable);
    }

    /**
     * Create takes the note's subject, which the caller supplies as the second
     * argument: `Gate::allows('create', [Note::class, $lead])`.
     */
    public function create(User $user, ?Model $subject = null): bool
    {
        return $user->can('timeline.create') && $this->canViewSubject($user, $subject);
    }

    /**
     * Nobody rewrites somebody else's note. An entry in a record's history that
     * a third party can edit is not history.
     */
    public function update(User $user, Note $note): bool
    {
        return $user->can('timeline.update')
            && $note->author_id === $user->id
            && $this->canViewSubject($user, $note->notable);
    }

    /**
     * The author can retract what they wrote; anyone who may change the record
     * itself can tidy their own record's timeline.
     */
    public function delete(User $user, Note $note): bool
    {
        if (! $user->can('timeline.delete') || ! $this->canViewSubject($user, $note->notable)) {
            return false;
        }

        return $note->author_id === $user->id || $this->canUpdateSubject($user, $note->notable);
    }

    private function canViewSubject(User $user, ?Model $subject): bool
    {
        return $subject !== null && Gate::forUser($user)->allows('view', $subject);
    }

    private function canUpdateSubject(User $user, ?Model $subject): bool
    {
        return $subject !== null && Gate::forUser($user)->allows('update', $subject);
    }
}
