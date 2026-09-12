<?php

namespace App\Domain\Products\Cpq;

use App\Domain\Sales\Contracts\SellingDocument;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Models\DocumentLine;
use App\Domain\Settings\SettingsManager;
use Illuminate\Database\Eloquent\Model;

/**
 * How much may be given away without asking.
 *
 * The threshold is a **percentage of the document**, not of a line. Ten per
 * cent off one line of a twenty-line quote is not the same concession as ten
 * per cent off everything, and a per-line rule lets somebody give away half the
 * value of a quote in ten-per-cent slices without ever crossing it.
 *
 * Lines are still reported individually, because "which line is the problem" is
 * the question whoever has to approve it will ask.
 */
class DiscountRules
{
    public const SETTING = 'sales.max_discount_percent';

    /**
     * The limit, as a percentage. Zero or unset means no limit — an
     * installation that has not configured this should not find its quotes
     * blocked.
     */
    public function limit(): float
    {
        $configured = app(SettingsManager::class)->get(self::SETTING);

        return $configured === null || $configured === '' ? 0.0 : max(0.0, (float) $configured);
    }

    public function hasLimit(): bool
    {
        return $this->limit() > 0.0;
    }

    /**
     * What proportion of this document has been discounted away.
     */
    public function discountPercent(Model&SellingDocument $document): float
    {
        $totals = $document->totals();

        if ($totals->gross <= 0.0) {
            return 0.0;
        }

        return round($totals->discount / $totals->gross * 100, 2);
    }

    /**
     * Whether this document needs somebody's agreement before it goes out.
     */
    public function needsApproval(Model&SellingDocument $document): bool
    {
        return $this->hasLimit() && $this->discountPercent($document) > $this->limit();
    }

    /**
     * The lines that are discounted beyond the limit, worst first.
     *
     * For the person approving: they want to see which line is the problem, not
     * a single percentage for the whole document.
     *
     * @return array<int, array{line: DocumentLine, percent: float}>
     */
    public function offendingLines(Model&SellingDocument $document): array
    {
        $limit = $this->limit();

        if ($limit <= 0.0) {
            return [];
        }

        $offenders = [];

        foreach ($document->documentLines() as $line) {
            $percent = $this->linePercent($line);

            if ($percent > $limit) {
                $offenders[] = ['line' => $line, 'percent' => $percent];
            }
        }

        // Worst first: whoever has to approve it wants the problem line at the
        // top, not to read down a list to find it.
        usort($offenders, static fn (array $a, array $b): int => $b['percent'] <=> $a['percent']);

        /** @var array<int, array{line: DocumentLine, percent: float}> $offenders */
        return $offenders;
    }

    /**
     * How much a single line has been discounted, as a percentage.
     *
     * Works for both discount kinds: a fixed amount off is converted to the
     * proportion it represents, so a rule written in percentages still catches
     * "500 off a 1000 line".
     */
    public function linePercent(DocumentLine $line): float
    {
        $type = $line->discountType();
        $value = (float) $line->discount_value;

        if ($type === null || $value <= 0) {
            return 0.0;
        }

        if ($type === DiscountType::Percentage) {
            return min(100.0, round($value, 2));
        }

        $gross = round($line->quantity() * (float) $line->unit_price, 2);

        return $gross <= 0.0 ? 0.0 : min(100.0, round($value / $gross * 100, 2));
    }

    /**
     * What the approval request says, in words somebody can act on.
     */
    public function summaryFor(Model&SellingDocument $document): string
    {
        $percent = $this->discountPercent($document);
        $reference = method_exists($document, 'reference') ? $document->reference() : 'This document';

        return sprintf(
            '%s is discounted %s%%, over the %s%% limit. Total %s.',
            $reference,
            rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format($this->limit(), 2, '.', ''), '0'), '.'),
            number_format($document->totals()->total, 2),
        );
    }
}
