<?php

namespace App\Domain\Deals\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Deals\Enums\StageOutcome;
use Database\Factories\PipelineStageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One checkpoint along a pipeline.
 *
 * @property int $id
 * @property int $pipeline_id
 * @property string $key
 * @property string $name
 * @property string $color
 * @property int $probability
 * @property string $outcome
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Pipeline $pipeline
 */
class PipelineStage extends Model
{
    /** @use HasFactory<PipelineStageFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = ['pipeline_id', 'key', 'name', 'color', 'probability', 'outcome', 'position'];

    /**
     * Columns that share a name with a method on this model. Without a default
     * here, an instance created without the column would have Laravel resolve
     * the property read as a relation and fail — see
     * .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'outcome' => 'open',
        'color' => 'slate',
        'probability' => 0,
    ];

    protected function casts(): array
    {
        return [
            'probability' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return ['pipeline_id', 'key', 'name', 'color', 'probability', 'outcome', 'position'];
    }

    public static function activitySubjectLabel(): string
    {
        return 'Pipeline stage';
    }

    /**
     * @return BelongsTo<Pipeline, $this>
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    /**
     * The deals sitting in this stage.
     *
     * Matched on the stage key within the same pipeline, which is how a deal
     * stores its position — see the migration for why it is not a foreign key.
     *
     * @return HasMany<Deal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'pipeline_id', 'pipeline_id')
            ->where('stage', $this->getAttributeValue('key'));
    }

    public function outcome(): StageOutcome
    {
        return StageOutcome::tryFrom((string) $this->getAttributeValue('outcome')) ?? StageOutcome::Open;
    }

    public function isClosed(): bool
    {
        return $this->outcome()->isClosed();
    }

    public function isOpen(): bool
    {
        return ! $this->isClosed();
    }

    public function displayName(): string
    {
        return $this->name;
    }

    /**
     * Turn a name into a key that will not collide inside this pipeline.
     *
     * Keys are permanent: a deal stores one, so reusing or reassigning a key
     * would silently move deals between stages.
     *
     * @param  array<int, string>  $taken
     */
    public static function keyFrom(string $name, array $taken = []): string
    {
        $base = str($name)->slug('_')->limit(48, '')->toString();

        if ($base === '') {
            $base = 'stage';
        }

        $key = $base;
        $suffix = 2;

        while (in_array($key, $taken, true)) {
            $key = $base.'_'.$suffix;
            $suffix++;
        }

        return $key;
    }

    /**
     * @param  Builder<PipelineStage>  $query
     * @return Builder<PipelineStage>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($query->qualifyColumn('position'))
            ->orderBy($query->qualifyColumn('id'));
    }
}
