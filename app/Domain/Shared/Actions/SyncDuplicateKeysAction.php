<?php

namespace App\Domain\Shared\Actions;

use App\Domain\Shared\Duplicates\DuplicateFinder;
use App\Domain\Shared\Duplicates\DuplicateSource;
use App\Domain\Shared\Models\DuplicateKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Brings a record's stored fingerprints in line with its current values.
 *
 * Called after every write that could change a matched field. Rebuilding the
 * set rather than diffing it keeps this idempotent, which matters because it
 * also has to be safe to run over a whole table after an import.
 */
class SyncDuplicateKeysAction
{
    public function __construct(private readonly DuplicateFinder $finder) {}

    public function __invoke(DuplicateSource $source, Model $record): void
    {
        $wanted = $this->finder->fingerprints($source, $record);

        $this->forget($source, $record);

        if ($wanted === []) {
            return;
        }

        DuplicateKey::query()->insert(array_map(fn (array $row) => [
            'keyable_type' => $source->modelClass(),
            'keyable_id' => $record->getKey(),
            'kind' => $row['kind'],
            'value' => $row['value'],
        ], $wanted));
    }

    /**
     * Drop a record's fingerprints, so it stops being a candidate at all.
     *
     * Used when a record is merged away or removed: leaving the rows behind
     * would keep offering a resolved duplicate as an unresolved one.
     */
    public function forget(DuplicateSource $source, Model $record): void
    {
        DuplicateKey::query()
            ->where('keyable_type', $source->modelClass())
            ->where('keyable_id', $record->getKey())
            ->delete();
    }

    /**
     * Rebuild the fingerprints for a whole module, for after an import or a
     * change to how a field is normalised.
     *
     * @param  Builder<covariant Model>|null  $query  Defaults to every record.
     * @return int How many records were fingerprinted.
     */
    public function backfill(DuplicateSource $source, ?Builder $query = null): int
    {
        $query ??= $source->modelClass()::query();
        $synced = 0;

        // Chunked by id so a large table does not have to fit in memory.
        $query->chunkById(500, function ($records) use ($source, &$synced) {
            foreach ($records as $record) {
                $this($source, $record);
                $synced++;
            }
        });

        return $synced;
    }
}
