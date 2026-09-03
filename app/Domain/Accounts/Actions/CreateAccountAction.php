<?php

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\DTOs\AccountData;
use App\Domain\Accounts\Models\Account;
use App\Models\User;
use RuntimeException;

class CreateAccountAction
{
    /**
     * @throws RuntimeException when the chosen parent is not a real account
     */
    public function __invoke(AccountData $data, User $actor): Account
    {
        $attributes = $data->toAttributes();

        // An account always has an owner; unassigned records are how visibility
        // scoping springs a leak.
        $attributes['owner_id'] ??= $actor->id;

        if ($data->parentId !== null && ! Account::query()->whereKey($data->parentId)->exists()) {
            throw new RuntimeException('That parent account does not exist.');
        }

        return Account::create($attributes);
    }
}
