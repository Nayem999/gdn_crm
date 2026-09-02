<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string|null $description
 */
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'parent_id',
        'name',
        'description',
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Team, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * Users whose active team is this one, so record visibility follows it.
     *
     * @return HasMany<User, $this>
     */
    public function activeMembers(): HasMany
    {
        return $this->hasMany(User::class, 'current_team_id');
    }

    /**
     * This team's parent chain, nearest first.
     *
     * @return Collection<int, Team>
     */
    public function ancestors(): Collection
    {
        $ancestors = new Collection;
        $team = $this->parent;

        // Guarded by depth as well as visited ids: a cycle in existing data
        // must not spin here.
        $seen = [$this->id];

        while ($team !== null && ! in_array($team->id, $seen, true)) {
            $ancestors->push($team);
            $seen[] = $team->id;
            $team = $team->parent;
        }

        return $ancestors;
    }

    /**
     * Every team beneath this one, at any depth.
     *
     * @return Collection<int, Team>
     */
    public function descendants(): Collection
    {
        $descendants = new Collection;

        foreach ($this->children as $child) {
            $descendants->push($child);
            $descendants = $descendants->merge($child->descendants());
        }

        return $descendants;
    }

    public function isDescendantOf(Team $team): bool
    {
        return $this->ancestors()->contains(fn (Team $ancestor) => $ancestor->is($team));
    }

    /**
     * How deep this team sits, with a root team at zero.
     */
    public function depth(): int
    {
        return $this->ancestors()->count();
    }
}
