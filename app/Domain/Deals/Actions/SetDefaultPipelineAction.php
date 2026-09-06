<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Deals\Models\Pipeline;
use Illuminate\Support\Facades\DB;

/**
 * Make one pipeline the default, and no other.
 *
 * The one-true rule lives here rather than in a unique index: "exactly one row
 * with true, any number with false" is not something a unique constraint says,
 * and a partial index is not portable. So every path that sets the flag goes
 * through this action — nothing else may write is_default.
 */
class SetDefaultPipelineAction
{
    public function __invoke(Pipeline $pipeline): Pipeline
    {
        DB::transaction(function () use ($pipeline) {
            Pipeline::query()
                ->where('is_default', true)
                ->whereKeyNot($pipeline->getKey())
                ->update(['is_default' => false]);

            $pipeline->forceFill(['is_default' => true])->save();
        });

        return $pipeline->refresh();
    }

    /**
     * Make sure something is the default, for a database where the flag was
     * lost — a default deleted around the guard, or a restore from a partial
     * dump. Returns the pipeline holding it, or null when there are none.
     */
    public function ensureOneExists(): ?Pipeline
    {
        $current = Pipeline::query()->where('is_default', true)->first();

        if ($current !== null) {
            return $current;
        }

        $first = Pipeline::query()->ordered()->first();

        return $first === null ? null : $this($first);
    }
}
