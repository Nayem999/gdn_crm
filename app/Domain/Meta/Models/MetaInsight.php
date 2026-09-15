<?php

namespace App\Domain\Meta\Models;

use App\Domain\Meta\Enums\MetaAdLevel;
use Database\Factories\MetaInsightFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One day of one thing's figures.
 *
 * Stored per day rather than as a running total, because every question anybody
 * asks of advertising spend has a period in it — this week against last, the
 * fortnight the offer ran, the month before the price changed — and a total
 * cannot be cut into periods afterwards.
 *
 * **`read_at` is part of the fact, not bookkeeping.** Meta's attribution windows
 * move a day's figures for up to seventy-two hours: the spend for Tuesday is not
 * final on Wednesday, and a dashboard that showed it as though it were would be
 * quietly wrong in the direction of under-reporting. The column is what lets
 * 12.13 say "as read at" rather than implying a live number.
 *
 * @property int $id
 * @property string $level
 * @property string $entity_id
 * @property Carbon $date
 * @property string $spend
 * @property int $impressions
 * @property int $reach
 * @property int $clicks
 * @property string|null $ctr
 * @property string|null $cpc
 * @property string|null $cpm
 * @property int $leads
 * @property int $conversions
 * @property string|null $currency
 * @property Carbon $read_at
 */
class MetaInsight extends Model
{
    /** @use HasFactory<MetaInsightFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'level',
        'entity_id',
        'date',
        'spend',
        'impressions',
        'reach',
        'clicks',
        'ctr',
        'cpc',
        'cpm',
        'leads',
        'conversions',
        'currency',
        'read_at',
    ];

    /**
     * `level()` shares its name with the column — see
     * .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'level' => MetaAdLevel::Campaign->value,
        'spend' => 0,
        'impressions' => 0,
        'reach' => 0,
        'clicks' => 0,
        'leads' => 0,
        'conversions' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'spend' => 'decimal:2',
            'impressions' => 'integer',
            'reach' => 'integer',
            'clicks' => 'integer',
            'leads' => 'integer',
            'conversions' => 'integer',
            'read_at' => 'datetime',
        ];
    }

    public function level(): MetaAdLevel
    {
        return MetaAdLevel::tryFrom((string) $this->getAttributeValue('level')) ?? MetaAdLevel::Campaign;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAtLevel(Builder $query, MetaAdLevel $level): Builder
    {
        return $query->where($query->qualifyColumn('level'), $level->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween($query->qualifyColumn('date'), [$from->toDateString(), $to->toDateString()]);
    }

    /**
     * @param  Builder<self>  $query
     * @param  array<int, string>  $entityIds
     * @return Builder<self>
     */
    public function scopeForEntities(Builder $query, array $entityIds): Builder
    {
        return $query->whereIn($query->qualifyColumn('entity_id'), $entityIds);
    }
}
