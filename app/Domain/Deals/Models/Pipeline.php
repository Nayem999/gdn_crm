<?php

namespace App\Domain\Deals\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use Database\Factories\PipelineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A route a deal is worked along.
 *
 * Not scoped by access level: a pipeline is configuration everybody works
 * inside, like a role or a status. Who may *change* one is PipelinePolicy's
 * question, and it is a single administrative permission.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property bool $is_default
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Pipeline extends Model
{
    /** @use HasFactory<PipelineFactory> */
    use HasFactory;

    use RecordsActivity;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'description', 'is_default', 'position'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return ['name', 'description', 'is_default', 'position'];
    }

    // -- Relations ----------------------------------------------------------

    /**
     * @return HasMany<PipelineStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class)->ordered();
    }

    /**
     * @return HasMany<Deal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    // -- Reading ------------------------------------------------------------

    /**
     * The pipeline a deal gets when nobody chooses one.
     *
     * Falls back to the first by position rather than returning null, so a
     * database whose default flag was lost still resolves to something usable.
     */
    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first()
            ?? static::query()->ordered()->first();
    }

    public function stageByKey(string $key): ?PipelineStage
    {
        return $this->stages->firstWhere('key', $key);
    }

    /**
     * Where a new deal on this pipeline starts.
     *
     * The first *open* stage, so a pipeline whose stages were reordered to put
     * a closing one first does not create deals that are already closed. Falls
     * back to the first stage of any kind, and null only when there are none.
     */
    public function openingStage(): ?PipelineStage
    {
        return $this->stages->first(fn (PipelineStage $stage) => $stage->isOpen())
            ?? $this->stages->first();
    }

    /**
     * Whether this pipeline can be removed, and why not when it cannot.
     *
     * Read by both the policy and the action, so the button that is hidden and
     * the request that is refused always agree.
     */
    public function deletionBlocker(): ?string
    {
        if ($this->is_default) {
            return 'The default pipeline cannot be removed. Make another one the default first.';
        }

        if (static::query()->count() <= 1) {
            return 'This is the only pipeline, so it cannot be removed.';
        }

        $deals = $this->deals()->withTrashed()->count();

        if ($deals > 0) {
            return 'This pipeline still has '.$deals.' '.str('deal')->plural($deals).' on it.';
        }

        return null;
    }

    public function canBeDeleted(): bool
    {
        return $this->deletionBlocker() === null;
    }

    public function displayName(): string
    {
        return $this->name;
    }

    // -- Queries -------------------------------------------------------------

    /**
     * @param  Builder<Pipeline>  $query
     * @return Builder<Pipeline>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($query->qualifyColumn('position'))
            ->orderBy($query->qualifyColumn('id'));
    }
}
