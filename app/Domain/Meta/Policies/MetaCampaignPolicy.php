<?php

namespace App\Domain\Meta\Policies;

use App\Domain\Meta\Models\MetaCampaign;
use App\Models\User;

/**
 * Who may read Meta's advertising and who may tie it to a CRM campaign.
 *
 * Its own permissions rather than `meta.view` / `meta.manage`, because the
 * audiences genuinely differ: a marketing manager should be able to read what
 * was spent and decide which CRM campaign it belongs to without also holding the
 * permission that disconnects the company's lead capture.
 *
 * No access-level scoping. An advertising campaign is not somebody's record —
 * it is what the company spent — and a spend figure with holes in it, because
 * half the rows belong to another sales team, is a figure nobody can reconcile
 * against the invoice from Meta.
 */
class MetaCampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('meta.campaigns.view');
    }

    public function view(User $user, MetaCampaign $campaign): bool
    {
        return $user->can('meta.campaigns.view');
    }

    /**
     * Linking and unlinking. The only change a person makes to one of these
     * rows — everything else about it is Meta's, and the sync's.
     */
    public function update(User $user, MetaCampaign $campaign): bool
    {
        return $user->can('meta.campaigns.manage');
    }

    /**
     * Asking Meta now rather than waiting for the quarter-hour.
     *
     * Takes no campaign, deliberately: it is a check about the integration, not
     * about a row, and it is asked from a screen that has no row in hand. A
     * policy method that required one could not be called with a class name at
     * all — which is the mistake this signature exists to prevent repeating.
     */
    public function sync(User $user): bool
    {
        return $user->can('meta.sync');
    }
}
