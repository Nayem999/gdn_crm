<?php

namespace App\Domain\Attribution\Concerns;

use App\Domain\Attribution\MarketingAttribution;
use App\Domain\Attribution\Models\RecordAttribution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Where this record came from, and how that survives what happens to it.
 *
 * Three things a module gets by using this, and each is a thing §13 and §37 of
 * the brief ask for and a CRM usually gets wrong:
 *
 * - **Recording** it once, from whatever created the record.
 * - **Carrying** it forward when a lead becomes an account, a contact and a
 *   deal — the moment attribution is most often lost, because conversion
 *   creates records that never saw the advertisement.
 * - **Keeping** it through a merge, where the survivor's own answer wins and
 *   the loser only fills the gaps.
 */
trait HasMarketingAttribution
{
    /**
     * @return MorphOne<RecordAttribution, $this>
     */
    public function marketingAttribution(): MorphOne
    {
        return $this->morphOne(RecordAttribution::class, 'attributable');
    }

    /**
     * What is known about where this came from. Never null: a record with no
     * stored attribution has an empty one, so every caller can ask it questions
     * without checking first.
     */
    public function attribution(): MarketingAttribution
    {
        $stored = $this->relationLoaded('marketingAttribution')
            ? $this->getRelation('marketingAttribution')
            : $this->marketingAttribution()->first();

        return $stored instanceof RecordAttribution
            ? $stored->toValue()
            : new MarketingAttribution;
    }

    public function hasAttribution(): bool
    {
        return ! $this->attribution()->isEmpty();
    }

    /**
     * Write it, replacing whatever was there.
     *
     * An empty attribution writes nothing and removes nothing: "we learned
     * nothing new" is not the same as "forget what you knew", and a capture
     * pipeline that could not tell them apart would wipe a lead's history the
     * first time somebody edited it by hand.
     */
    public function recordAttribution(MarketingAttribution $attribution): void
    {
        if ($attribution->isEmpty()) {
            return;
        }

        $this->marketingAttribution()->updateOrCreate([], $attribution->toAttributes());

        $this->unsetRelation('marketingAttribution');
    }

    /**
     * Add what is known to what is already stored, without overwriting it.
     *
     * What a second touch does: a lead that arrived from an advertisement and
     * later filled in a form on the site is still attributed to the
     * advertisement, and the form tells us the fields the advertisement did not.
     */
    public function enrichAttribution(MarketingAttribution $attribution): void
    {
        if ($attribution->isEmpty()) {
            return;
        }

        $this->recordAttribution($this->attribution()->mergedWith($attribution));
    }

    /**
     * Give another record this one's attribution.
     *
     * Used by conversion. The target keeps anything it already knew — an
     * account that was already attributed is not re-attributed by the second
     * lead that happened to convert into it.
     */
    public function copyAttributionTo(Model $target): void
    {
        $attribution = $this->attribution();

        if ($attribution->isEmpty() || ! method_exists($target, 'enrichAttribution')) {
            return;
        }

        $target->enrichAttribution($attribution);
    }

    /**
     * Take what a merged-away duplicate knew.
     *
     * The survivor's own answer wins field by field; the loser fills the gaps.
     * Neither record knows more than it knows, and picking one wholesale would
     * throw away half of what the pair had between them.
     */
    public function absorbAttributionFrom(Model $loser): void
    {
        if (! method_exists($loser, 'attribution')) {
            return;
        }

        $theirs = $loser->attribution();

        if ($theirs->isEmpty()) {
            return;
        }

        $this->recordAttribution($this->attribution()->mergedWith($theirs));
    }
}
