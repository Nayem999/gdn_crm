<?php

namespace App\Domain\Sales\Policies;

use App\Domain\Sales\Models\Quote;
use App\Models\User;

/**
 * Quotes follow the access levels every other business model does, with two
 * additions that are about the document rather than the record:
 *
 * - **Editing follows the status, not only the permission.** A sent quote is
 *   not editable by anybody, because the customer holds a copy of it.
 * - **Sending is its own permission.** It puts a priced offer in front of a
 *   customer over the company's name, which is more than an edit.
 */
class QuotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('quotes.view');
    }

    public function view(User $user, Quote $quote): bool
    {
        return $user->can('quotes.view') && $this->isVisibleTo($user, $quote);
    }

    public function create(User $user): bool
    {
        return $user->can('quotes.create');
    }

    public function update(User $user, Quote $quote): bool
    {
        return $user->can('quotes.update')
            && $quote->isEditable()
            && $this->isVisibleTo($user, $quote);
    }

    public function delete(User $user, Quote $quote): bool
    {
        return $user->can('quotes.delete') && $this->isVisibleTo($user, $quote);
    }

    public function send(User $user, Quote $quote): bool
    {
        return $user->can('quotes.send') && $this->isVisibleTo($user, $quote);
    }

    /**
     * Raising a new version is an edit of the *quote*, not of the sent
     * document, so it follows update rather than the status.
     */
    public function revise(User $user, Quote $quote): bool
    {
        return $user->can('quotes.update')
            && $quote->status()->canBeRevised()
            && $this->isVisibleTo($user, $quote);
    }

    public function export(User $user): bool
    {
        return $user->can('quotes.export');
    }

    private function isVisibleTo(User $user, Quote $quote): bool
    {
        return Quote::query()
            ->visibleTo($user)
            ->whereKey($quote->getKey())
            ->withTrashed()
            ->exists();
    }
}
