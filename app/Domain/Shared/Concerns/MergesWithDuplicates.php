<?php

namespace App\Domain\Shared\Concerns;

use App\Domain\Shared\Actions\SyncDuplicateKeysAction;
use App\Domain\Shared\Duplicates\DuplicateRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Duplicate fingerprints and where a merged-away record went.
 *
 * The fingerprints are kept in step by model events rather than by each write
 * action, deliberately. They are a derived index of the record's own columns —
 * the same relationship a search index has to its table — and a stale one does
 * not fail loudly, it silently stops finding duplicates. Hooking the six write
 * actions would leave task 2.7's import and the Phase 8 ingestion gateway free
 * to forget.
 *
 * A merged record is soft-deleted rather than destroyed, so its audit trail
 * stays attributable to the record the events actually happened to.
 *
 * @phpstan-require-extends Model
 */
trait MergesWithDuplicates
{
    /**
     * Eloquent calls boot{TraitName} for each trait, so this composes with the
     * other traits' boot hooks instead of fighting over booted().
     */
    protected static function bootMergesWithDuplicates(): void
    {
        static::saved(function (self $record) {
            $record->syncDuplicateKeys();
        });

        // Soft-deleted counts: a record nobody can reach is not a duplicate
        // anybody needs to resolve.
        static::deleted(function (self $record) {
            $record->forgetDuplicateKeys();
        });

        static::restored(function (self $record) {
            $record->syncDuplicateKeys();
        });
    }

    public function syncDuplicateKeys(): void
    {
        $source = DuplicateRegistry::sourceFor($this);

        if ($source !== null) {
            app(SyncDuplicateKeysAction::class)($source, $this);
        }
    }

    public function forgetDuplicateKeys(): void
    {
        $source = DuplicateRegistry::sourceFor($this);

        if ($source !== null) {
            app(SyncDuplicateKeysAction::class)->forget($source, $this);
        }
    }

    /**
     * @return BelongsTo<static, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(static::class, 'merged_into_id');
    }

    /**
     * Records folded into this one. Soft-deleted, so they need withTrashed().
     *
     * @return HasMany<static, $this>
     */
    public function mergedRecords(): HasMany
    {
        return $this->hasMany(static::class, 'merged_into_id')->withTrashed();
    }

    public function isMerged(): bool
    {
        return $this->merged_into_id !== null;
    }

    /**
     * Records still worth working: a merged-away one is a resolved duplicate.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNotMerged(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('merged_into_id'));
    }
}
