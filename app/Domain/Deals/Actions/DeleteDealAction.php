<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Deals\Models\Deal;

class DeleteDealAction
{
    /**
     * Soft delete a deal.
     *
     * Its notes and documents stay attached rather than being removed: the
     * record is recoverable, and a restored deal with its history stripped is
     * worse than one that was never deleted.
     */
    public function __invoke(Deal $deal): void
    {
        $deal->delete();
    }
}
