<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Models\Lead;

/**
 * Rescores one lead, called after anything that changes its details.
 */
class ScoreLeadAction
{
    public function __construct(private readonly ScoreLeadsAction $scoreLeads) {}

    public function __invoke(Lead $lead): Lead
    {
        ($this->scoreLeads)(Lead::query()->whereKey($lead->getKey()));

        return $lead->refresh();
    }
}
