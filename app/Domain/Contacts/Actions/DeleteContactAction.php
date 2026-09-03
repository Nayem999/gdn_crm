<?php

namespace App\Domain\Contacts\Actions;

use App\Domain\Contacts\Models\Contact;
use Illuminate\Support\Facades\DB;

class DeleteContactAction
{
    public function __construct(private readonly SetPrimaryContactAction $primary) {}

    /**
     * Soft delete a contact, passing the primary flag on if they held it.
     *
     * An account with people should always have one of them marked primary,
     * otherwise "who do we call" quietly has no answer.
     */
    public function __invoke(Contact $contact): void
    {
        $accountId = $contact->account_id;

        DB::transaction(function () use ($contact, $accountId) {
            $contact->delete();

            $this->primary->backfill($accountId);
        });
    }
}
