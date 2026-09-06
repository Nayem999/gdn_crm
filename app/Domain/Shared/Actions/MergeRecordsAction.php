<?php

namespace App\Domain\Shared\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\Duplicates\DuplicateSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Folds one record into another.
 *
 * The loser is stamped and soft-deleted, never destroyed. Its audit entries go
 * on pointing at the record the events actually happened to, which is the only
 * way "merge preserves history" can be true — re-pointing them at the survivor
 * would rewrite who did what to which record, and deleting the row would leave
 * them dangling.
 *
 * Everything this touches is declared by the module's DuplicateSource: the
 * fields it may write, the rows it may move. Nothing comes from the request.
 */
class MergeRecordsAction
{
    /**
     * @param  array<string, mixed>  $chosen  Field => value, filtered against mergeableFields().
     *
     * @throws RuntimeException when the two records cannot be merged
     */
    public function __invoke(
        DuplicateSource $source,
        Model $survivor,
        Model $loser,
        array $chosen = [],
    ): Model {
        $this->guard($source, $survivor, $loser);

        $attributes = array_intersect_key($chosen, $source->mergeableFields());
        // Read before the merge: the loser's own values may be about to move to
        // the survivor, and the entry should name it as it was.
        $loserLabel = $source->label($loser);

        DB::transaction(function () use ($source, $survivor, $loser, $attributes, $loserLabel) {
            if ($attributes !== []) {
                $survivor->forceFill($attributes)->save();
            }

            foreach ($source->inboundRelations() as $relation) {
                DB::table($relation['table'])
                    ->where($relation['column'], $loser->getKey())
                    // A polymorphic table is addressed by type as well as id,
                    // so without this a note on contact 7 would follow a merge
                    // of account 7.
                    ->where($relation['where'] ?? [])
                    ->update([$relation['column'] => $survivor->getKey()]);
            }

            $loser->forceFill([
                'merged_into_id' => $survivor->getKey(),
                'merged_at' => now(),
            ])->save();

            $loser->delete();

            // Whatever the module has to put right now the rows have moved: a
            // hierarchy that would loop, a flag that lives in an action.
            $source->afterMerge($survivor->refresh(), $loser);

            AuditLogger::merged(
                $survivor,
                $loser,
                $source->label($survivor),
                $loserLabel,
            );
        });

        // Fingerprints look after themselves: MergesWithDuplicates syncs on
        // save and forgets on delete, so the survivor picks up any email or
        // phone it took and the loser stops being a candidate.
        return $survivor->refresh();
    }

    /**
     * @throws RuntimeException
     */
    private function guard(DuplicateSource $source, Model $survivor, Model $loser): void
    {
        $class = $source->modelClass();

        if (! $survivor instanceof $class || ! $loser instanceof $class) {
            throw new RuntimeException('Those records are not the same kind of thing.');
        }

        if ($survivor->getKey() === $loser->getKey()) {
            throw new RuntimeException('A record cannot be merged into itself.');
        }

        foreach ([$survivor, $loser] as $record) {
            if ($record->getAttribute('merged_into_id') !== null) {
                throw new RuntimeException(
                    $source->label($record).' has already been merged into another record.'
                );
            }
        }
    }
}
