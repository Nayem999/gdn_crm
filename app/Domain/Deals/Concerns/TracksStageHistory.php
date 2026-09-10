<?php

namespace App\Domain\Deals\Concerns;

use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\DealStageEntry;
use App\Domain\Deals\Models\PipelineStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Records every stage a deal sits in, and for how long.
 *
 * Hooked to model events rather than to the write actions, for the same reason
 * duplicate fingerprints are (see MergesWithDuplicates): the history is derived
 * from the record's own column, and a missing visit does not fail loudly — it
 * quietly leaves a gap in a report somebody will later trust. MoveDealStageAction
 * is the only writer of `stage` today, but lead conversion, the factories and
 * whatever Phase 8's ingestion gateway turns into all write deals, and any of
 * them could forget to call a recorder.
 *
 * Exactly one visit per deal is open at a time. Every write goes through
 * recordStageEntry(), which closes whatever was open before it opens the next,
 * so the invariant holds however the stage was changed.
 *
 * @phpstan-require-extends Model
 */
trait TracksStageHistory
{
    /**
     * Eloquent calls boot{TraitName} per trait, so this composes with the other
     * traits' boot hooks instead of fighting over booted().
     */
    protected static function bootTracksStageHistory(): void
    {
        static::created(function (self $deal) {
            $deal->recordStageEntry();
        });

        static::updated(function (self $deal) {
            if ($deal->wasChanged('stage')) {
                $deal->recordStageEntry();
            }
        });
    }

    /**
     * The stage the deal is in as its pipeline defines it, or null when nothing
     * matches.
     *
     * Declared rather than probed for: this trait belongs to the Deals domain
     * and only ever sits on Deal, so the dependency is stated the way
     * RecordsActivity states activityAttributes().
     */
    abstract public function configuredStage(): ?PipelineStage;

    /**
     * @return HasMany<DealStageEntry, $this>
     */
    public function stageEntries(): HasMany
    {
        return $this->hasMany(DealStageEntry::class, 'deal_id')->ordered();
    }

    /**
     * Close the visit in progress and open one for where the deal is now.
     */
    public function recordStageEntry(): DealStageEntry
    {
        return DB::transaction(function () {
            $previous = $this->closeOpenStageEntries();

            $stage = $this->configuredStage();
            $key = (string) $this->getAttributeValue('stage');

            // The first visit starts when the deal did, not when this ran: a
            // deal seeded with a backdated created_at should not read as having
            // arrived today.
            $enteredAt = $previous === null ? ($this->created_at ?? now()) : now();

            return DealStageEntry::query()->create([
                'deal_id' => $this->getKey(),
                'pipeline_id' => $this->getAttribute('pipeline_id'),
                'stage_key' => $key,
                // Copied, not joined: a stage can be renamed or removed, and
                // history that changes retrospectively is not history. A key
                // matching no configured stage stands in for its own name.
                'stage_name' => $stage === null ? $key : $stage->name,
                'outcome' => $stage === null ? StageOutcome::Open->value : $stage->outcome,
                'entered_at' => $enteredAt,
                'moved_by_id' => auth()->id(),
            ]);
        });
    }

    /**
     * End any visit still open, stamping how long it lasted.
     *
     * Returns the one that was closed, or null when there was none — which is
     * how the first visit knows it is the first.
     */
    public function closeOpenStageEntries(): ?DealStageEntry
    {
        $closed = null;
        $at = now();

        foreach ($this->stageEntries()->stillOpen()->get() as $entry) {
            $entry->forceFill([
                'left_at' => $at,
                'duration_seconds' => (int) $entry->entered_at->diffInSeconds($at),
            ])->save();

            $closed = $entry;
        }

        return $closed;
    }

    public function currentStageEntry(): ?DealStageEntry
    {
        return $this->stageEntries()->stillOpen()->latest('id')->first();
    }

    /**
     * How long the deal has been where it is, in seconds.
     */
    public function secondsInCurrentStage(): int
    {
        return $this->currentStageEntry()?->seconds() ?? 0;
    }

    /**
     * Total time spent in each stage, summed across repeat visits.
     *
     * A deal that went back to Proposal has been there twice, and what somebody
     * asks is how long it spent there altogether.
     *
     * @return array<string, array{key: string, name: string, seconds: int, visits: int}>
     */
    public function timePerStage(): array
    {
        $totals = [];

        foreach ($this->stageEntries()->get() as $entry) {
            $key = $entry->stage_key;

            $totals[$key] ??= ['key' => $key, 'name' => $entry->stage_name, 'seconds' => 0, 'visits' => 0];
            $totals[$key]['seconds'] += $entry->seconds();
            $totals[$key]['visits']++;
            // The latest name wins, so a renamed stage reads as it is called now
            // while each visit keeps the name it had.
            $totals[$key]['name'] = $entry->stage_name;
        }

        return $totals;
    }

    /**
     * Creation to close, or to now while the deal is still open.
     */
    public function cycleSeconds(): int
    {
        $from = $this->created_at;

        if ($from === null) {
            return 0;
        }

        $to = $this->getAttribute('closed_at') ?? now();

        return (int) $from->diffInSeconds($to);
    }
}
