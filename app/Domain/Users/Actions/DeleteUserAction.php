<?php

namespace App\Domain\Users\Actions;

use App\Models\User;
use RuntimeException;

class DeleteUserAction
{
    /**
     * Soft delete a user. Records they own keep pointing at the row so history
     * stays intact; the SoftDeletes scope stops them authenticating.
     *
     * @throws RuntimeException when a user tries to remove their own account
     */
    public function __invoke(User $user, User $actor): void
    {
        if ($user->is($actor)) {
            throw new RuntimeException('You cannot delete your own account.');
        }

        $user->delete();
    }
}
