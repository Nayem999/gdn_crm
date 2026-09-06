<?php

namespace App\Domain\Timeline\Policies;

use App\Domain\Timeline\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Same rule as NotePolicy: a document is as visible as the record it hangs off.
 *
 * This one is load-bearing beyond the screen. Document files sit on the private
 * disk and are streamed through documents.download, and `view` is what that
 * route asks — so this policy is the only thing standing between a customer
 * contract and anyone who guesses a document id.
 */
class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return $user->can('timeline.view') && $this->canViewSubject($user, $document->documentable);
    }

    public function create(User $user, ?Model $subject = null): bool
    {
        return $user->can('timeline.create') && $this->canViewSubject($user, $subject);
    }

    public function update(User $user, Document $document): bool
    {
        return $user->can('timeline.update')
            && $document->uploaded_by_id === $user->id
            && $this->canViewSubject($user, $document->documentable);
    }

    public function delete(User $user, Document $document): bool
    {
        if (! $user->can('timeline.delete') || ! $this->canViewSubject($user, $document->documentable)) {
            return false;
        }

        return $document->uploaded_by_id === $user->id || $this->canUpdateSubject($user, $document->documentable);
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
