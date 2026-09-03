<?php

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Models\Account;
use Illuminate\Support\Facades\DB;

class DeleteAccountAction
{
    /**
     * Soft delete an account, lifting its subsidiaries to the top level.
     *
     * They are not deleted with it: a subsidiary is a real customer in its own
     * right, and cascading would quietly remove records nobody asked about.
     */
    public function __invoke(Account $account): void
    {
        DB::transaction(function () use ($account) {
            $account->children()->update(['parent_id' => null]);

            $account->delete();
        });
    }
}
