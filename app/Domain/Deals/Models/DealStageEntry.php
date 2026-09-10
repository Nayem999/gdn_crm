<?php

namespace App\Domain\Deals\Models;

use App\Domain\Deals\Enums\StageOutcome;
use App\Models\User;
use Database\Factories\DealStageEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One visit a deal made to a stage.
 *
 * Written only by TracksStageHistory. Nothing edits a visit after the fact —
 * a record of what happened that can be revised is not a record.
 *
 * @property int $id
 * @property int $deal_id
 * @property int|null $pipeline_id
 * @property string $stage_key
 * @property string $stage_name
 * @property string $outcome
 * @property Carbon $entered_at
 * @property Carbon|null $left_at
 * @property int|null $duration_seconds
 * @property int|null $moved_by_id
 * @property-read Deal $deal
 * @property-read User|null $movedBy
 */
class DealStageEntry extends Model
{
    /** @use HasFactory<DealStageEntryFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'deal_id', 'pipeline_id', 'stage_key', 'stage_name',
        'outcome', 'entered_at', 'left_at', 'duration_seconds', 'moved_by_id',
    ];

    /**
     * A column sharing its name with a method needs a default, or an instance
     * missing it resolves the property read as a relation and fails — see
     * .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'outcome' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'entered_at' => 'datetime',
            'left_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * @return BelongsTo<Pipeline, $this>
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function movedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by_id');
    }

    public function outcome(): StageOutcome
    {
        return StageOutcome::tryFrom((string) $this->getAttributeValue('outcome')) ?? StageOutcome::Open;
    }

    /**
     * Whether the deal is still in this stage.
     */
    public function isOpen(): bool
    {
        return $this->left_at === null;
    }

    /**
     * How long the visit lasted, counting up to now while it is still open.
     *
     * The stored figure is used once the visit has ended, so a report and this
     * page cannot disagree about a closed visit.
     */
    public function seconds(): int
    {
        if ($this->left_at !== null) {
            return (int) ($this->duration_seconds ?? $this->entered_at->diffInSeconds($this->left_at));
        }

        return (int) $this->entered_at->diffInSeconds(now());
    }

    /**
     * The duration as somebody would say it.
     *
     * Whole days once there is more than one, because "3 days" is what a sales
     * manager asks about and "3 days 4 hours 11 minutes" is not.
     */
    public function forHumans(): string
    {
        $seconds = $this->seconds();

        return match (true) {
            $seconds < 60 => 'under a minute',
            $seconds < 3600 => max(1, intdiv($seconds, 60)).' min',
            $seconds < 86400 => max(1, intdiv($seconds, 3600)).' hr',
            default => ($days = intdiv($seconds, 86400)).' '.str('day')->plural($days),
        };
    }

    /**
     * @param  Builder<DealStageEntry>  $query
     * @return Builder<DealStageEntry>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        // id is the tiebreaker, not decoration: two visits stamped in the same
        // second would otherwise come back in arbitrary order, and the history
        // reads as a sequence.
        return $query->orderBy($query->qualifyColumn('entered_at'))
            ->orderBy($query->qualifyColumn('id'));
    }

    /**
     * @param  Builder<DealStageEntry>  $query
     * @return Builder<DealStageEntry>
     */
    public function scopeStillOpen(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('left_at'));
    }
}
