<?php

namespace App\Domain\Shared\Concerns;

use App\Domain\Shared\Enums\DataAccessLevel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Restricts a model's query results to what the acting user's role(s) allow.
 *
 * Record-level visibility is never applied with ad-hoc `where()` calls in
 * controllers or Livewire components. Call `Model::visibleTo($user)` instead.
 */
trait ScopesByAccessLevel
{
    /**
     * Scope the query to the records visible to the given user, based on the
     * broadest `data_access_level` (own/team/all) across all of the user's roles.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $ownerColumn = $this->qualifyColumn($this->accessLevelOwnerColumn());

        return match (static::resolveAccessLevelFor($user)) {
            DataAccessLevel::All => $query,
            DataAccessLevel::Own => $query->where($ownerColumn, $user->id),
            DataAccessLevel::Team => $query->where(function (Builder $scoped) use ($user, $ownerColumn) {
                if ($user->current_team_id === null) {
                    $scoped->where($ownerColumn, $user->id);

                    return;
                }

                $scoped->whereIn(
                    $ownerColumn,
                    User::query()->where('current_team_id', $user->current_team_id)->select('id')
                );
            }),
        };
    }

    /**
     * The broadest data access level granted by any of the user's roles.
     * A user with no roles, or no role carrying a recognised level, defaults to "own".
     */
    public static function resolveAccessLevelFor(User $user): DataAccessLevel
    {
        /** @var Collection<int, DataAccessLevel> $levels */
        $levels = $user->roles
            ->pluck('data_access_level')
            ->filter()
            ->map(fn (string $value) => DataAccessLevel::tryFrom($value))
            ->filter();

        return match (true) {
            $levels->contains(DataAccessLevel::All) => DataAccessLevel::All,
            $levels->contains(DataAccessLevel::Team) => DataAccessLevel::Team,
            default => DataAccessLevel::Own,
        };
    }

    /**
     * The column that identifies the owning user of a record.
     * Override with a protected `$accessLevelOwnerColumn` property when a model uses a different column.
     */
    protected function accessLevelOwnerColumn(): string
    {
        return property_exists($this, 'accessLevelOwnerColumn') ? $this->accessLevelOwnerColumn : 'owner_id';
    }
}
