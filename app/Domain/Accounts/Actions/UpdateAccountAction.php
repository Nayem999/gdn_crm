<?php

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\DTOs\AccountData;
use App\Domain\Accounts\Models\Account;
use RuntimeException;

class UpdateAccountAction
{
    /**
     * @throws RuntimeException when the chosen parent would create a loop
     */
    public function __invoke(Account $account, AccountData $data): Account
    {
        $attributes = $data->toUpdateAttributes();

        if ($data->parentId !== null) {
            $parent = Account::query()->find($data->parentId);

            if ($parent === null) {
                throw new RuntimeException('That parent account does not exist.');
            }

            // Refused here as well as in the form: an account cannot be its own
            // ancestor, and a loop would hang every hierarchy walk.
            if (! $account->canBeParentedBy($parent)) {
                throw new RuntimeException('An account cannot sit beneath itself.');
            }
        }

        // The owner only moves when one was actually chosen, so an edit that
        // leaves the field alone cannot silently unassign the record.
        if ($data->ownerId !== null) {
            $attributes['owner_id'] = $data->ownerId;
        }

        $account->update($attributes);

        return $account->refresh();
    }
}
