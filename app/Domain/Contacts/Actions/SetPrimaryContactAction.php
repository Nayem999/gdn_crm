<?php

namespace App\Domain\Contacts\Actions;

use App\Domain\Contacts\Models\Contact;
use Illuminate\Support\Facades\DB;

/**
 * Keeps "one primary contact per account" true.
 *
 * Enforced here rather than by a unique index: MySQL treats every NULL as
 * distinct, so a unique index on (account_id, is_primary) would happily allow
 * two primaries once account_id is NULL, and would forbid two *non*-primary
 * contacts at the same account — the opposite of what is wanted.
 *
 * Every write path goes through this, so there is one place where the rule
 * lives and one place to read it.
 */
class SetPrimaryContactAction
{
    /**
     * Make this contact its account's primary, demoting whoever held it.
     *
     * @return bool False when the contact has no account, since "primary" only
     *              means anything relative to one.
     */
    public function promote(Contact $contact): bool
    {
        if ($contact->account_id === null) {
            return false;
        }

        DB::transaction(function () use ($contact) {
            Contact::query()
                ->where('account_id', $contact->account_id)
                ->whereKeyNot($contact->getKey())
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            $contact->forceFill(['is_primary' => true])->save();
        });

        return true;
    }

    public function demote(Contact $contact): void
    {
        if (! $contact->is_primary) {
            return;
        }

        $contact->forceFill(['is_primary' => false])->save();
    }

    /**
     * Apply an intended primary flag, whatever it is.
     */
    public function apply(Contact $contact, bool $shouldBePrimary): void
    {
        $shouldBePrimary ? $this->promote($contact) : $this->demote($contact);
    }

    /**
     * Called after a contact leaves an account, or is removed.
     *
     * An account should not be left with nobody marked primary while it still
     * has people, so the longest-standing remaining contact takes over.
     */
    public function backfill(?int $accountId): void
    {
        if ($accountId === null) {
            return;
        }

        $hasPrimary = Contact::query()
            ->where('account_id', $accountId)
            ->where('is_primary', true)
            ->exists();

        if ($hasPrimary) {
            return;
        }

        $successor = Contact::query()
            ->where('account_id', $accountId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        $successor?->forceFill(['is_primary' => true])->save();
    }
}
