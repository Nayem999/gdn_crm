<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Models\Lead;

class DeleteLeadAction
{
    /**
     * Soft delete a lead. The row stays so history and any conversion trail
     * still resolve.
     */
    public function __invoke(Lead $lead): void
    {
        $lead->delete();
    }
}
