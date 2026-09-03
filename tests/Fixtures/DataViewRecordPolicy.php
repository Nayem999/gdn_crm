<?php

namespace Tests\Fixtures;

use App\Models\User;

/**
 * Stands in for a module's real policy so the kit's authorisation path is
 * genuinely exercised: closed records are read-only.
 */
class DataViewRecordPolicy
{
    public function update(User $user, DataViewRecord $record): bool
    {
        return $record->stage !== 'lost';
    }
}
