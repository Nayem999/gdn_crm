<?php

namespace App\Domain\Meta\Ads;

use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Meta\Models\MetaCampaign;
use App\Models\User;
use RuntimeException;

/**
 * Pointing a Meta campaign at the CRM campaign it is part of.
 *
 * The one place `meta_campaigns.campaign_id` is written. The sync deliberately
 * cannot touch it: a link is a decision somebody made about what this
 * advertising is *for*, and a job running every fifteen minutes must not be able
 * to change it.
 *
 * **One Meta campaign to one CRM campaign, both ways.** The database enforces it
 * with a unique index; this refuses it in words first, because "that campaign is
 * already linked to Spring offer" is something somebody can act on and a
 * constraint violation is not. Two Meta campaigns sharing a CRM campaign would
 * double its spend in every figure derived from it, with nothing on the screen
 * to say why the number looked wrong.
 *
 * **Nothing is copied.** `campaigns.actual_cost` is not touched — it is a typed
 * figure that belongs to a person, and Meta's spend is summed from
 * `meta_insights` at read time. See .ai/rules/ads.md.
 *
 * Reversible, and reversible cleanly: unlinking leaves both records exactly as
 * they were, because nothing about either was rewritten to make the link.
 */
class LinkMetaCampaignAction
{
    /**
     * @throws RuntimeException when either side is already spoken for
     */
    public function link(MetaCampaign $metaCampaign, Campaign $campaign, User $actor): MetaCampaign
    {
        // Re-linking to the same campaign is not an error, and not a no-op
        // worth refusing: somebody clicking twice should see the state they
        // asked for.
        if ($metaCampaign->campaign_id === $campaign->id) {
            return $metaCampaign;
        }

        if ($metaCampaign->campaign_id !== null) {
            $current = $metaCampaign->campaign;

            throw new RuntimeException(sprintf(
                '"%s" is already linked to %s. Unlink it first.',
                $metaCampaign->name,
                // The row can outlive the campaign in a request that has one
                // loaded from before a deletion, so this is not assumed.
                $current === null ? 'another campaign' : $current->name,
            ));
        }

        $taken = MetaCampaign::query()
            ->where('campaign_id', $campaign->id)
            ->whereKeyNot($metaCampaign->getKey())
            ->first();

        if ($taken !== null) {
            throw new RuntimeException(sprintf(
                '%s is already linked to the Meta campaign "%s". One campaign, one link.',
                $campaign->name,
                $taken->name,
            ));
        }

        $metaCampaign->forceFill([
            'campaign_id' => $campaign->id,
            // Who and when, because a figure that suddenly includes Meta's spend
            // is a change somebody will ask about.
            'linked_at' => now(),
            'linked_by_id' => $actor->getKey(),
        ])->save();

        return $metaCampaign->refresh();
    }

    public function unlink(MetaCampaign $metaCampaign, User $actor): MetaCampaign
    {
        if ($metaCampaign->campaign_id === null) {
            return $metaCampaign;
        }

        $metaCampaign->forceFill([
            'campaign_id' => null,
            'linked_at' => null,
            // Kept: the audit trail records that this person unlinked it, and
            // clearing who last touched the link would lose half of that.
            'linked_by_id' => $actor->getKey(),
        ])->save();

        return $metaCampaign->refresh();
    }
}
