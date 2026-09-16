<?php

namespace App\Domain\Meta\Analytics;

/**
 * One line of the attribution chain, with the arithmetic that follows from it.
 *
 * A value object rather than an array because every derived figure here has a
 * division in it, and a division in a Blade template is a division nobody
 * tested. The rule for all of them is the same and it is the only rule that
 * matters:
 *
 * **A figure with no denominator is null, never zero and never infinity.** A
 * campaign that has spent ৳40,000 and produced no leads yet has *no*
 * cost-per-lead — printing ৳40,000 would be wrong and printing ৳0 would be a
 * lie in the opposite direction. The screen shows a dash, which is the truth:
 * the question cannot be answered yet.
 */
readonly class PerformanceRow
{
    public function __construct(
        public string $id,
        public string $name,
        public float $spend = 0.0,
        /** What Meta charged in — the ad account's currency, not ours. */
        public ?string $currency = null,
        public int $impressions = 0,
        public int $clicks = 0,
        public int $leads = 0,
        public int $qualified = 0,
        public int $deals = 0,
        public int $won = 0,
        public float $revenue = 0.0,
        /**
         * What the revenue is in — the company's currency, which is often not
         * the one the advertising was billed in. A Bangladeshi business paying
         * Meta in dollars and invoicing in taka is the ordinary case, not the
         * exception.
         */
        public ?string $revenueCurrency = null,
        /** Meta's own reported lead count, which is not ours. See below. */
        public int $reportedLeads = 0,
    ) {}

    /**
     * Cost per lead: what this campaign paid for each enquiry it produced.
     */
    public function costPerLead(): ?float
    {
        return $this->per($this->spend, $this->leads);
    }

    /**
     * Cost per **qualified** lead — the figure that separates a campaign
     * producing enquiries from one producing customers. A campaign with a
     * cheap CPL and a dreadful CPQL is buying the wrong people.
     */
    public function costPerQualifiedLead(): ?float
    {
        return $this->per($this->spend, $this->qualified);
    }

    /**
     * Cost per acquisition: spend divided by deals actually won.
     */
    public function costPerAcquisition(): ?float
    {
        return $this->per($this->spend, $this->won);
    }

    /**
     * Leads that became won deals, as a percentage.
     */
    public function conversionRate(): ?float
    {
        return $this->leads === 0 ? null : round(($this->won / $this->leads) * 100, 1);
    }

    /**
     * Return on investment, as a percentage of what was spent.
     *
     * Revenue **minus** spend over spend, so 100% means the campaign doubled
     * its money and 0% means it broke even — not the ratio some tools print as
     * "ROI" where 100% means it lost everything.
     *
     * **Null when the two sides are in different currencies.** Subtracting
     * dollars from taka produces a number with no meaning, and a percentage is
     * exactly the shape that hides it: an installation paying Meta in USD and
     * invoicing in BDT would read a return of several hundred thousand per cent
     * and believe it. Converting would need a rate this application does not
     * have and could not date correctly — the spend is from the day it was
     * charged, the revenue from the day the deal closed — so it refuses to
     * answer rather than inventing one.
     */
    public function roi(): ?float
    {
        if ($this->spend <= 0.0 || $this->mixesCurrencies()) {
            return null;
        }

        return round((($this->revenue - $this->spend) / $this->spend) * 100, 1);
    }

    /**
     * Whether the money on the two sides of this row is the same money.
     *
     * Unknown currencies are treated as comparable: an installation that has
     * never set one is not asking a question about exchange rates, and refusing
     * every figure would be worse than answering in whatever it uses.
     */
    public function mixesCurrencies(): bool
    {
        return $this->currency !== null
            && $this->revenueCurrency !== null
            && $this->currency !== $this->revenueCurrency;
    }

    /**
     * Whether Meta's own lead count disagrees with the CRM's.
     *
     * Worth surfacing rather than hiding: Meta counts a lead when the form is
     * submitted, and this CRM counts one when the lead arrives here. A gap
     * means deliveries are being lost — a webhook that is not subscribed, or a
     * form nobody mapped — and that is a fault somebody must fix, not a
     * rounding difference.
     */
    public function hasLeadGap(): bool
    {
        return $this->reportedLeads > 0 && $this->reportedLeads > $this->leads;
    }

    private function per(float $amount, int $count): ?float
    {
        return $count === 0 ? null : round($amount / $count, 2);
    }
}
