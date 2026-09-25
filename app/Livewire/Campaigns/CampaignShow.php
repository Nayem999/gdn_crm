<?php

namespace App\Livewire\Campaigns;

use App\Domain\Campaigns\Actions\DeleteCampaignAction;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\PipelineStage;
use App\Domain\Leads\Models\Lead;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One campaign: what it cost, what it brought in, and what is still open.
 *
 * Every figure here is computed from the CRM's own records rather than stored
 * on the campaign. A cached "revenue" column would be wrong from the moment a
 * deal moved, and this page is precisely where somebody goes to find out
 * whether it moved.
 *
 * The related lists are scoped by the viewer's access level, which means two
 * people can legitimately see different numbers on the same campaign. That is
 * the same rule the reports follow, and the alternative — a total that includes
 * records the viewer may not open — leaks the shape of other people's pipelines.
 */
class CampaignShow extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $campaignId;

    public function mount(Campaign $campaign): void
    {
        $this->authorize('view', $campaign);

        $this->campaignId = $campaign->id;
    }

    public function campaign(): Campaign
    {
        return Campaign::query()->with('owner')->findOrFail($this->campaignId);
    }

    /**
     * The most recent leads it produced. A page, not all of them: a campaign
     * that worked has thousands, and the list screen is where they are browsed.
     *
     * @return Collection<int, Lead>
     */
    public function leads(): Collection
    {
        return Lead::query()
            ->visibleTo(auth()->user())
            ->where('campaign_id', $this->campaignId)
            ->with('assignees.user:id,name')
            ->latest('created_at')
            ->limit(10)
            ->get();
    }

    /**
     * @return Collection<int, Deal>
     */
    public function deals(): Collection
    {
        return Deal::query()
            ->visibleTo(auth()->user())
            ->where('campaign_id', $this->campaignId)
            ->with('owner:id,name')
            ->latest('created_at')
            ->limit(10)
            ->get();
    }

    /**
     * What the campaign is worth, as far as this viewer can see.
     *
     * Won revenue is the value of deals whose stage is a winning one — read
     * through the pipeline's own outcome rather than a hardcoded "won", because
     * a company that renamed its closing stage still closes business.
     *
     * @return array{leads: int, deals: int, won: int, revenue: float, cost: float, cost_per_lead: float|null, roi: float|null}
     */
    public function figures(): array
    {
        $campaign = $this->campaign();

        $leads = Lead::query()->visibleTo(auth()->user())->where('campaign_id', $this->campaignId)->count();

        $deals = Deal::query()->visibleTo(auth()->user())->where('campaign_id', $this->campaignId);

        $won = (clone $deals)->whereIn('stage', $this->winningStages());

        $revenue = (float) (clone $won)->sum('value');
        $cost = $campaign->cost();

        return [
            'leads' => $leads,
            'deals' => (clone $deals)->count(),
            'won' => (clone $won)->count(),
            'revenue' => $revenue,
            'cost' => $cost,
            // Null rather than zero when there is nothing to divide by: a
            // campaign with no cost has no cost per lead, and showing 0.00
            // would read as "free", which is a different claim.
            'cost_per_lead' => $leads > 0 && $cost > 0 ? round($cost / $leads, 2) : null,
            'roi' => $cost > 0 ? round((($revenue - $cost) / $cost) * 100, 1) : null,
        ];
    }

    /**
     * The stage keys that count as won, across every pipeline.
     *
     * @return array<int, string>
     */
    private function winningStages(): array
    {
        return PipelineStage::query()
            ->where('outcome', StageOutcome::Won->value)
            ->pluck('key')
            ->unique()
            ->values()
            ->all();
    }

    public function delete(): void
    {
        $campaign = $this->campaign();

        $this->authorize('delete', $campaign);

        app(DeleteCampaignAction::class)($campaign);

        $this->redirectRoute('campaigns.index', navigate: true);
    }

    /**
     * What the confirmation says will lose its attribution.
     *
     * @return array{leads: int, contacts: int, deals: int}
     */
    public function deletionImpact(): array
    {
        return app(DeleteCampaignAction::class)->impact($this->campaign());
    }

    public function render(): View
    {
        return view('livewire.campaigns.campaign-show', [
            'campaign' => $this->campaign(),
        ]);
    }
}
