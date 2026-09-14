<?php

namespace App\Domain\Campaigns\Actions;

use App\Domain\Campaigns\Models\Campaign;

/**
 * Removes a campaign.
 *
 * Nothing is refused, and nothing it won is touched. The foreign keys on leads,
 * contacts and deals null on delete, so the records survive with their campaign
 * forgotten — which is the right trade: a campaign is a label on work that
 * happened, and refusing to remove one because it produced customers would mean
 * a marketing list nobody can ever tidy.
 *
 * It is a soft delete, so the attribution is recoverable by restoring the
 * campaign — the records' own `campaign_id` is not, which is why the screen
 * says how many records will lose their attribution before anybody presses it.
 */
class DeleteCampaignAction
{
    public function __invoke(Campaign $campaign): void
    {
        $campaign->delete();
    }

    /**
     * What would lose its attribution, for the confirmation to show.
     *
     * @return array{leads: int, contacts: int, deals: int}
     */
    public function impact(Campaign $campaign): array
    {
        return [
            'leads' => $campaign->leads()->count(),
            'contacts' => $campaign->contacts()->count(),
            'deals' => $campaign->deals()->count(),
        ];
    }
}
