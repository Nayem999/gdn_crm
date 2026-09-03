<?php

namespace App\Domain\Contacts\Policies;

use App\Domain\Contacts\Models\Contact;
use App\Models\User;

/**
 * Both questions must pass: does this person hold the permission, and does this
 * record fall inside their access level? Visibility uses the same scope the
 * lists do, so a hidden record cannot be reached by guessing its id.
 */
class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('contacts.view');
    }

    public function view(User $user, Contact $contact): bool
    {
        return $user->can('contacts.view') && $this->isVisibleTo($user, $contact);
    }

    public function create(User $user): bool
    {
        return $user->can('contacts.create');
    }

    public function update(User $user, Contact $contact): bool
    {
        return $user->can('contacts.update') && $this->isVisibleTo($user, $contact);
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $user->can('contacts.delete') && $this->isVisibleTo($user, $contact);
    }

    public function export(User $user): bool
    {
        return $user->can('contacts.export');
    }

    private function isVisibleTo(User $user, Contact $contact): bool
    {
        return Contact::query()
            ->visibleTo($user)
            ->whereKey($contact->getKey())
            ->withTrashed()
            ->exists();
    }
}
