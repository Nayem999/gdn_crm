<?php

namespace App\Livewire\Meta;

use App\Domain\Meta\Analytics\CampaignPerformance;
use App\Domain\Meta\Analytics\PerformanceRow;
use App\Domain\Meta\Models\MetaCampaign;
use App\Domain\Meta\Models\MetaInsight;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * What the advertising cost, and what the CRM got for it.
 *
 * The one screen where Meta's money and this company's revenue are in the same
 * table. It exists because neither side can answer the question alone: Ads
 * Manager knows the spend and stops at the form submission, and the CRM knows
 * which deals were won and nothing about what they cost.
 *
 * Drilling down rather than three screens — campaign, then its ad sets, then
 * its advertisements — because that is the shape of the question people
 * actually ask: "which campaign is working" is followed immediately by "which
 * part of it".
 */
#[Title('Meta performance')]
class MetaPerformance extends Component
{
    use AuthorizesRequests;

    #[Url(as: 'from', except: '')]
    public string $from = '';

    #[Url(as: 'to', except: '')]
    public string $to = '';

    /**
     * The campaign being looked into, and then the ad set inside it.
     */
    #[Url(as: 'campaign', except: '')]
    public string $campaignId = '';

    #[Url(as: 'adset', except: '')]
    public string $adSetId = '';

    /**
     * Memoised for one render. Livewire rebuilds the component per request, so
     * this never outlives the figures it describes.
     */
    private ?PerformanceRow $total = null;

    public function mount(): void
    {
        $this->authorize('viewAny', MetaCampaign::class);

        // A month, which is how advertising is budgeted and talked about.
        $this->from = $this->from !== '' ? $this->from : Carbon::now()->subDays(29)->toDateString();
        $this->to = $this->to !== '' ? $this->to : Carbon::now()->toDateString();
    }

    public function updatedFrom(): void
    {
        $this->clampDates();
    }

    public function updatedTo(): void
    {
        $this->clampDates();
    }

    public function openCampaign(string $campaignId): void
    {
        $this->campaignId = $campaignId;
        $this->adSetId = '';
    }

    public function openAdSet(string $adSetId): void
    {
        $this->adSetId = $adSetId;
    }

    public function back(): void
    {
        $this->adSetId !== '' ? $this->adSetId = '' : $this->campaignId = '';
    }

    /**
     * @return Collection<int, PerformanceRow>
     */
    public function rows(): Collection
    {
        $performance = app(CampaignPerformance::class);
        $viewer = auth()->user();

        return match (true) {
            $this->adSetId !== '' => $performance->ads($viewer, $this->adSetId, $this->start(), $this->end()),
            $this->campaignId !== '' => $performance->adSets($viewer, $this->campaignId, $this->start(), $this->end()),
            default => $performance->campaigns($viewer, $this->start(), $this->end()),
        };
    }

    /**
     * Held for the render rather than asked twice: the cards and the table both
     * want it, and each call is three aggregates over the whole period.
     */
    public function total(): PerformanceRow
    {
        return $this->total ??= app(CampaignPerformance::class)
            ->total(auth()->user(), $this->start(), $this->end());
    }

    /**
     * What is being looked at, in words, so the table's heading is never
     * ambiguous about which level its rows are.
     */
    public function level(): string
    {
        return match (true) {
            $this->adSetId !== '' => 'Advertisements',
            $this->campaignId !== '' => 'Ad sets',
            default => 'Campaigns',
        };
    }

    /**
     * Which campaign is being looked inside, at either depth.
     */
    public function crumb(): ?string
    {
        if ($this->campaignId === '') {
            return null;
        }

        return MetaCampaign::query()->where('meta_campaign_id', $this->campaignId)->value('name');
    }

    /**
     * When Meta's figures were last read.
     *
     * Shown beside the totals rather than left implicit: Meta restates a day's
     * spend for up to 72 hours, so a figure is only meaningful next to the
     * moment it was fetched. A screen that implied live numbers would be lying
     * about how certain they are.
     */
    public function readAt(): ?Carbon
    {
        $read = MetaInsight::query()->max('read_at');

        return $read === null ? null : Carbon::parse($read);
    }

    /**
     * Whether a row on this level opens into something.
     *
     * An advertisement is the bottom: below it Meta has creatives, which are
     * pictures rather than figures.
     */
    public function canDrill(): bool
    {
        return $this->adSetId === '';
    }

    /**
     * The headline figures, as the dashboard's own cards read them.
     *
     * @return array<int, array{label: string, value: string, hint: string|null}>
     */
    public function cards(): array
    {
        $total = $this->total();

        return [
            ['label' => 'Spend', 'value' => $this->money($total->spend, $total->currency), 'hint' => $total->impressions > 0 ? number_format($total->impressions).' impressions' : null],
            ['label' => 'Leads', 'value' => number_format($total->leads), 'hint' => $total->qualified.' qualified'],
            ['label' => 'Cost per lead', 'value' => $this->money($total->costPerLead(), $total->currency), 'hint' => 'Per qualified: '.$this->money($total->costPerQualifiedLead(), $total->currency)],
            ['label' => 'Revenue', 'value' => $this->money($total->revenue, $total->currency), 'hint' => $total->won.' won of '.$total->deals.' opened'],
        ];
    }

    /**
     * An amount with its own currency, or a dash.
     *
     * The dash matters: a figure with no denominator has no answer, and zero
     * would be a different claim. The currency travels with the row because an
     * agency running one account in BDT and another in USD must never see the
     * two added together.
     */
    public function money(?float $amount, ?string $currency): string
    {
        if ($amount === null) {
            return '—';
        }

        // A campaign Meta charged nothing for in this period carries no
        // currency of its own, so it borrows the one every other row agrees on
        // rather than printing a bare number beside labelled ones.
        $currency ??= $this->total()->currency;

        return trim(($currency ?? '').' '.number_format($amount, 2));
    }

    public function render(): View
    {
        return view('livewire.meta.meta-performance', [
            'rows' => $this->rows(),
            'total' => $this->total(),
        ]);
    }

    private function start(): Carbon
    {
        return Carbon::parse($this->from)->startOfDay();
    }

    private function end(): Carbon
    {
        return Carbon::parse($this->to)->endOfDay();
    }

    /**
     * Keep the range the right way round.
     *
     * Somebody setting "from" to after "to" gets an empty table and no
     * explanation, which reads as a fault in the integration rather than a
     * typing mistake.
     */
    private function clampDates(): void
    {
        if ($this->from === '' || $this->to === '') {
            return;
        }

        if (Carbon::parse($this->from)->gt(Carbon::parse($this->to))) {
            $this->to = $this->from;
        }
    }
}
