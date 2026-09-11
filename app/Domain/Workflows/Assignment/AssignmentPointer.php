<?php

namespace App\Domain\Workflows\Assignment;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Whose turn it is, for one round-robin step.
 *
 * @property int $id
 * @property string $key
 * @property int $position
 */
class AssignmentPointer extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['key', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * Take the next turn and move the pointer on.
     *
     * Inside a transaction with the row locked, because two records created at
     * the same moment would otherwise both read the same position and both be
     * given to the same person — which is precisely the thing round robin
     * exists to avoid, and precisely the bug that never shows up in testing.
     */
    public static function take(string $key, int $poolSize): int
    {
        if ($poolSize < 1) {
            return 0;
        }

        return DB::transaction(function () use ($key, $poolSize): int {
            // firstOrCreate outside the lock would race on the unique key; this
            // way the insert either wins or the row is there to lock.
            self::query()->firstOrCreate(['key' => $key], ['position' => 0]);

            /** @var self $pointer */
            $pointer = self::query()->where('key', $key)->lockForUpdate()->firstOrFail();

            $index = $pointer->position % $poolSize;

            $pointer->forceFill(['position' => $pointer->position + 1])->save();

            return $index;
        });
    }
}
