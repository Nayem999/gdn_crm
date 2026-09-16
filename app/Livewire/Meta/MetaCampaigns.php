<?php

namespace App\Livewire\Meta;

use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Meta\Ads\LinkMetaCampaignAction;
use App\Domain\Meta\Enums\MetaAdLevel;
use App\Domain\Meta\Models\MetaAdAccount;
use App\Domain\Meta\Models\MetaCampaign;
use App\Domain\Meta\Models\MetaInsight;
use App\Jobs\SyncMetaAds;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * What Meta is spending, and which CRM campaign each piece of it belongs to.
 *
 * The screen exists for one decision: the link. Everything else on it is there
 * to make that decision informed — a campaign's name means little without what
 * it cost and what it produced, and "Spring offer" at Meta is not always the
 * "Spring offer" somebody set up in the CRM.
 *
 * Read-only about Meta. Nothing here edits a budget, a status or a name: those
 * belong to Ads Manager, and a CRM that offered to change them would be offering
 * something it cannot honour.
 */
#[Title('Meta campaigns')]
class MetaCampaigns extends Component
{
    use AuthorizesRequests;

    public string $search = '';

    /**
     * The Meta campaign whose link is being chosen, if any.
     */
    public ?int $linking = null;

    public ?string $chosenCampaignId = null;

    public ?string $error = null;

    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorize('viewAny', MetaCampaign::class);
    }

    public function startLinking(int $metaCampaignId): void
    {
        $campaign = $this->find($metaCampaignId);

        $this->authorize('update', $campaign);

        $this->linking = $metaCampaignId;
        $this->chosenCampaignId = null;
        $this->error = null;
    }

    public function cancelLinking(): void
    {
        $this->linking = null;
        $this->chosenCampaignId = null;
    }

    public function link(LinkMetaCampaignAction $link): void
    {
        if ($this->linking === null) {
            return;
        }

        $metaCampaign = $this->find($this->linking);

        $this->authorize('update', $metaCampaign);

        // `exists` proves a campaign is real, never that this person may reach
        // it — so the chosen id is re-checked against the visibility scope on
        // submit, the same way lead conversion does it.
        $campaign = Campaign::query()
            ->visibleTo(auth()->user())
            ->whereKey((int) $this->chosenCampaignId)
            ->first();

        if ($campaign === null) {
            $this->error = 'Choose a campaign to link this to.';

            return;
        }

        try {
            $link->link($metaCampaign, $campaign, auth()->user());
        } catch (RuntimeException $exception) {
            // The refusal is the message: "already linked to Spring offer" is
            // something somebody can act on.
            $this->error = $exception->getMessage();

            return;
        }

        $this->notice = sprintf('"%s" is now part of %s.', $metaCampaign->name, $campaign->name);
        $this->cancelLinking();
    }

    public function unlink(int $metaCampaignId, LinkMetaCampaignAction $link): void
    {
        $metaCampaign = $this->find($metaCampaignId);

        $this->authorize('update', $metaCampaign);

        $link->unlink($metaCampaign, auth()->user());

        $this->error = null;
        $this->notice = sprintf('"%s" is no longer linked.', $metaCampaign->name);
    }

    /**
     * Ask Meta now rather than waiting for the quarter-hour.
     *
     * Queued, not run here: a person pressing a button should get an answer
     * immediately, and the answer is "it is being read", not fifteen seconds of
     * a spinner while somebody's ad account is walked.
     */
    public function sync(): void
    {
        $this->authorize('sync', MetaCampaign::class);

        $accounts = MetaAdAccount::query()->where('is_active', true)->get()
            ->filter(fn (MetaAdAccount $account) => $account->account?->isUsable() === true);

        foreach ($accounts as $account) {
            SyncMetaAds::dispatch($account->id);
        }

        $this->notice = $accounts->isEmpty()
            ? 'There is no connected ad account to read.'
            : 'Reading Meta now. The figures will appear here as they arrive.';
    }

    /**
     * The campaigns, with what each one spent and produced.
     *
     * @return EloquentCollection<int, MetaCampaign>
     */
    public function campaigns(): EloquentCollection
    {
        return MetaCampaign::query()
            ->with('campaign')
            ->when($this->search !== '', fn ($query) => $query->where('name', 'like', '%'.trim($this->search).'%'))
            ->orderByDesc('last_synced_at')
            ->orderBy('name')
            ->limit(100)
            ->get();
    }

    /**
     * What each campaign spent and what it delivered, as one grouped query
     * rather than one per row: a hundred campaigns should not be a hundred
     * round trips.
     *
     * **The rates are derived here, and the stored ones are not summed.** Meta
     * gives a CTR and a CPC per day, and adding those together means nothing —
     * a day with two impressions would weigh as heavily as a day with twenty
     * thousand. Over a period the only correct answer is clicks over
     * impressions and spend over clicks, which is what Ads Manager shows for a
     * range too.
     *
     * **Reach is summed and therefore is not unique people.** Meta counts a
     * person once per day; added across days, somebody reached on Monday and
     * Tuesday is counted twice. Ads Manager's own figure for a period will be
     * lower, and the screen says so rather than quietly disagreeing with it.
     *
     * @param  EloquentCollection<int, MetaCampaign>  $campaigns
     * @return array<string, array{spend: float, leads: int, impressions: int, clicks: int, reach: int, ctr: float|null, cpc: float|null, currency: string|null, read_at: string|null}>
     */
    public function figuresFor(EloquentCollection $campaigns): array
    {
        $ids = $campaigns->pluck('meta_campaign_id')->all();

        if ($ids === []) {
            return [];
        }

        return MetaInsight::query()
            ->atLevel(MetaAdLevel::Campaign)
            ->forEntities($ids)
            ->selectRaw(
                'entity_id, SUM(spend) as spend, SUM(leads) as leads, SUM(impressions) as impressions, '
                .'SUM(clicks) as clicks, SUM(reach) as reach, MAX(currency) as currency, MAX(read_at) as read_at'
            )
            ->groupBy('entity_id')
            ->get()
            ->mapWithKeys(function (MetaInsight $row): array {
                $currency = $row->getAttributeValue('currency');
                // What the figures are as of. Meta's attribution moves a day's
                // spend for up to seventy-two hours, so the screen says when it
                // asked rather than implying a live number.
                $readAt = $row->getAttributeValue('read_at');

                $spend = (float) $row->getAttributeValue('spend');
                $impressions = (int) $row->getAttributeValue('impressions');
                $clicks = (int) $row->getAttributeValue('clicks');

                return [
                    (string) $row->entity_id => [
                        'spend' => $spend,
                        'leads' => (int) $row->getAttributeValue('leads'),
                        'impressions' => $impressions,
                        'clicks' => $clicks,
                        'reach' => (int) $row->getAttributeValue('reach'),
                        // Null rather than zero where there is no denominator:
                        // a campaign nobody has seen has no click-through rate,
                        // and 0% would be a claim about how it performed.
                        'ctr' => $impressions === 0 ? null : round(($clicks / $impressions) * 100, 2),
                        'cpc' => $clicks === 0 ? null : round($spend / $clicks, 2),
                        // Aggregates come back untyped from the driver, so both
                        // are narrowed rather than asserted.
                        'currency' => is_string($currency) ? $currency : null,
                        'read_at' => is_string($readAt) ? $readAt : null,
                    ],
                ];
            })
            ->all();
    }

    /**
     * The CRM campaigns still free to be linked to, as the select expects them.
     *
     * Scoped to what this person may see, and excluding any already spoken for:
     * offering a campaign that the action would refuse is offering a dead end.
     *
     * @return array<string, string>
     */
    public function campaignOptions(): array
    {
        $taken = MetaCampaign::query()->linked()->pluck('campaign_id')->all();

        /** @var array<string, string> $options */
        $options = Campaign::query()
            ->visibleTo(auth()->user())
            ->whereNotIn('id', $taken)
            ->orderBy('name')
            ->limit(200)
            ->pluck('name', 'id')
            ->all();

        return $options;
    }

    public function render(): View
    {
        $campaigns = $this->campaigns();

        return view('livewire.meta.meta-campaigns', [
            'campaigns' => $campaigns,
            'figures' => $this->figuresFor($campaigns),
            'campaignOptions' => $this->campaignOptions(),
        ]);
    }

    private function find(int $metaCampaignId): MetaCampaign
    {
        return MetaCampaign::query()->findOrFail($metaCampaignId);
    }
}
