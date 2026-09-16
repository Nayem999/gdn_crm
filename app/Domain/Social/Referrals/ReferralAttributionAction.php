<?php

namespace App\Domain\Social\Referrals;

use App\Domain\Attribution\MarketingAttribution;
use App\Domain\Meta\Models\MetaAd;
use App\Domain\Social\Enums\SocialChannel;
use Illuminate\Support\Carbon;

/**
 * An advertisement's referral, turned into what the CRM counts by.
 *
 * The referral names an ad id and nothing else. Everything a person wants to
 * read — which campaign, which ad set, what the advertisement was called — is in
 * the tables 12.7 syncs, joined on that id.
 *
 * **The names are copied, not looked up later.** An attribution row keeps the
 * campaign's name as it was on the day the lead arrived, because campaigns are
 * renamed constantly ("Eid Sale" becomes "Eid Sale — old") and a report that
 * reads today's name onto last year's lead quietly rewrites history. The ids
 * stay alongside, so a report that wants the live name can still join.
 *
 * **An unknown ad id is still attribution.** If the ad has not been synced — it
 * was created this morning, or it belongs to an ad account nobody connected —
 * the ids and the headline the customer saw are recorded without them. That is
 * strictly better than dropping the lead's origin because a background job has
 * not run yet, and the next sync makes the names resolvable for every report
 * that joins on the id.
 */
class ReferralAttributionAction
{
    public function __invoke(
        ClickToMessageReferral $referral,
        SocialChannel $channel,
        ?string $detail = null,
        ?Carbon $capturedAt = null,
    ): MarketingAttribution {
        $ad = $referral->namesAnAd()
            ? MetaAd::query()->with(['adSet', 'campaign'])->where('meta_ad_id', $referral->adId)->first()
            : null;

        return new MarketingAttribution(
            source: $channel->leadSource(),
            // What the advertisement said, in preference to the customer's own
            // name: "came from the Eid Sale advertisement" is the fact somebody
            // opening this lead wants, and the name is already on the record.
            sourceDetail: $referral->label() ?? $detail,
            metaCampaignId: $ad?->meta_campaign_id,
            metaCampaignName: $ad?->campaign?->name,
            metaAdSetId: $ad?->meta_ad_set_id,
            metaAdSetName: $ad?->adSet?->name,
            metaAdId: $referral->adId,
            metaAdName: $ad?->name,
            // The click, not the ad: this is what Meta joins a reported
            // conversion back to, and it is unique to this one customer's tap.
            clickId: $referral->clickId,
            capturedAt: $capturedAt ?? Carbon::now(),
        );
    }
}
