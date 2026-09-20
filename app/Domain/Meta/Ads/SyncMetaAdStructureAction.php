<?php

namespace App\Domain\Meta\Ads;

use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Graph\MetaGraphClient;
use App\Domain\Meta\Models\MetaAd;
use App\Domain\Meta\Models\MetaAdAccount;
use App\Domain\Meta\Models\MetaAdSet;
use App\Domain\Meta\Models\MetaCampaign;

/**
 * The shape of an ad account: its campaigns, their ad sets, their ads.
 *
 * Read separately from the figures, and on the same run, because they answer
 * different questions and go stale at different speeds. A campaign's name and
 * budget change when somebody changes them; its spend changes continuously.
 *
 * **Everything is upserted and nothing is ever deleted.** A campaign missing
 * from Meta's answer — archived, or absent because a permission changed for an
 * afternoon — keeps its row, its link to a CRM campaign and every lead
 * attributed to it. Deleting it would take last quarter's figures with it, and a
 * transient blip would look exactly like a deliberate removal.
 *
 * **The link is never written here.** `campaign_id` belongs to
 * `LinkMetaCampaignAction`, and a sync that could touch it would be a sync that
 * silently re-aims somebody's reporting every fifteen minutes.
 */
class SyncMetaAdStructureAction
{
    /**
     * Meta returns budgets in **minor units** — "5000" is fifty pounds, not five
     * thousand. Getting this wrong is a hundredfold error that looks entirely
     * plausible on a screen, which is why it is converted in one place.
     */
    private const MINOR_UNITS = 100;

    private const CAMPAIGN_FIELDS = 'id,name,objective,status,effective_status,daily_budget,lifetime_budget,start_time,stop_time';

    private const AD_SET_FIELDS = 'id,name,campaign_id,status,effective_status,optimization_goal,billing_event,daily_budget,lifetime_budget,start_time,end_time';

    private const AD_FIELDS = 'id,name,adset_id,campaign_id,status,effective_status,creative{name,title,body}';

    public function __construct(private readonly MetaGraphClient $client) {}

    /**
     * @return array{campaigns: int, ad_sets: int, ads: int, throttled: bool}
     *
     * @throws MetaApiException
     */
    public function __invoke(MetaAdAccount $account): array
    {
        // The ad account's own token where it was given one. A business handed
        // a separate permanent token for the Marketing API has exactly that,
        // and reaching past it to the connection's token means these calls run
        // on a credential nobody chose for them.
        $token = $account->usableToken();

        if ($token === null) {
            throw new MetaApiException('That Meta connection has no access token. Reconnect it.');
        }

        $counts = ['campaigns' => 0, 'ad_sets' => 0, 'ads' => 0, 'throttled' => false];

        foreach (['campaigns', 'ad_sets', 'ads'] as $level) {
            // Asked between levels as well as between pages: carrying on past
            // Meta's ceiling gets the whole application throttled, which breaks
            // every other integration too, and what is left will still be there
            // in an hour.
            if ($this->client->isNearRateLimit()) {
                $counts['throttled'] = true;

                break;
            }

            $counts[$level] = match ($level) {
                'campaigns' => $this->campaigns($account, $token),
                'ad_sets' => $this->adSets($account, $token),
                default => $this->ads($account, $token),
            };
        }

        $account->forceFill(['last_synced_at' => now()])->save();

        return $counts;
    }

    /**
     * @throws MetaApiException
     */
    private function campaigns(MetaAdAccount $account, string $token): int
    {
        $seen = 0;

        foreach ($this->read($account, 'campaigns', self::CAMPAIGN_FIELDS, $token) as $row) {
            $id = $this->id($row);

            if ($id === null) {
                continue;
            }

            $campaign = MetaCampaign::query()->firstOrNew(['meta_campaign_id' => $id]);

            $campaign->forceFill([
                'ad_account_id' => $account->ad_account_id,
                'name' => $this->text($row, 'name') ?? $campaign->name ?? 'Campaign',
                'objective' => $this->text($row, 'objective'),
                'status' => $this->text($row, 'status'),
                'effective_status' => $this->text($row, 'effective_status'),
                'daily_budget' => $this->money($row, 'daily_budget'),
                'lifetime_budget' => $this->money($row, 'lifetime_budget'),
                'start_time' => $this->text($row, 'start_time'),
                'stop_time' => $this->text($row, 'stop_time'),
                'last_synced_at' => now(),
            ])->save();

            $seen++;
        }

        return $seen;
    }

    /**
     * @throws MetaApiException
     */
    private function adSets(MetaAdAccount $account, string $token): int
    {
        $seen = 0;

        foreach ($this->read($account, 'adsets', self::AD_SET_FIELDS, $token) as $row) {
            $id = $this->id($row);
            $campaignId = $this->text($row, 'campaign_id');

            // An ad set with no campaign is not something Meta sends, and
            // writing one would produce a row that can never roll up into
            // anything.
            if ($id === null || $campaignId === null) {
                continue;
            }

            $adSet = MetaAdSet::query()->firstOrNew(['meta_ad_set_id' => $id]);

            $adSet->forceFill([
                'meta_campaign_id' => $campaignId,
                'name' => $this->text($row, 'name') ?? $adSet->name ?? 'Ad set',
                'status' => $this->text($row, 'status'),
                'effective_status' => $this->text($row, 'effective_status'),
                // Meta spells it the American way in its API and this
                // application spells it the British way in its columns. The
                // translation happens here, once.
                'optimisation_goal' => $this->text($row, 'optimization_goal'),
                'billing_event' => $this->text($row, 'billing_event'),
                'daily_budget' => $this->money($row, 'daily_budget'),
                'lifetime_budget' => $this->money($row, 'lifetime_budget'),
                'start_time' => $this->text($row, 'start_time'),
                'end_time' => $this->text($row, 'end_time'),
                'last_synced_at' => now(),
            ])->save();

            $seen++;
        }

        return $seen;
    }

    /**
     * @throws MetaApiException
     */
    private function ads(MetaAdAccount $account, string $token): int
    {
        $seen = 0;

        foreach ($this->read($account, 'ads', self::AD_FIELDS, $token) as $row) {
            $id = $this->id($row);
            $adSetId = $this->text($row, 'adset_id');

            if ($id === null || $adSetId === null) {
                continue;
            }

            $creative = is_array($row['creative'] ?? null) ? $row['creative'] : [];

            $ad = MetaAd::query()->firstOrNew(['meta_ad_id' => $id]);

            $ad->forceFill([
                'meta_ad_set_id' => $adSetId,
                'meta_campaign_id' => $this->text($row, 'campaign_id'),
                'name' => $this->text($row, 'name') ?? $ad->name ?? 'Ad',
                'status' => $this->text($row, 'status'),
                'effective_status' => $this->text($row, 'effective_status'),
                'creative_name' => $this->text($creative, 'name'),
                // The words somebody read, which is what makes an ad
                // recognisable in a list of ids.
                'creative_summary' => $this->summary($creative),
                'last_synced_at' => now(),
            ])->save();

            $seen++;
        }

        return $seen;
    }

    /**
     * One edge of the ad account, page by page.
     *
     * @return iterable<int, array<string, mixed>>
     *
     * @throws MetaApiException
     */
    private function read(MetaAdAccount $account, string $edge, string $fields, string $token): iterable
    {
        return $this->client->paginate($account->graphId().'/'.$edge, [
            'fields' => $fields,
            // Large pages on purpose: an account with two thousand ads is four
            // requests at this size and forty at Meta's default, and each
            // request is charged against the same rate limit.
            'limit' => 500,
        ], $token);
    }

    /**
     * Meta's minor units as the decimal the column holds.
     *
     * @param  array<string, mixed>  $row
     */
    private function money(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value / self::MINOR_UNITS, 2, '.', '');
    }

    /**
     * The title and body, joined into something readable.
     *
     * @param  array<string, mixed>  $creative
     */
    private function summary(array $creative): ?string
    {
        $parts = array_filter([
            $this->text($creative, 'title'),
            $this->text($creative, 'body'),
        ]);

        return $parts === [] ? null : mb_substr(implode(' — ', $parts), 0, 1000);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function id(array $row): ?string
    {
        return $this->text($row, 'id');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function text(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
