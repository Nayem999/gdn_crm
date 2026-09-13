<?php

namespace App\Domain\Reports\Models;

use App\Domain\Reports\Enums\ChartType;
use App\Models\User;
use Database\Factories\DashboardWidgetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One report on one person's dashboard.
 *
 * No audit trait: rearranging your own dashboard is not a business event, and
 * logging every drag would bury the entries that matter.
 *
 * @property int $id
 * @property int $user_id
 * @property int $report_id
 * @property string|null $title
 * @property string|null $chart_type
 * @property int $width
 * @property int $position
 */
class DashboardWidget extends Model
{
    /** @use HasFactory<DashboardWidgetFactory> */
    use HasFactory;

    /**
     * The widest a widget goes: the full three columns.
     */
    public const MAX_WIDTH = 3;

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'report_id', 'title', 'chart_type', 'width', 'position'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['width' => 1, 'position' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['width' => 'integer', 'position' => 'integer'];
    }

    /**
     * @return BelongsTo<Report, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Its own title, or the report's — so renaming the report renames the
     * widget until somebody deliberately titles it otherwise.
     */
    public function heading(): string
    {
        $title = $this->title;

        if ($title !== null && trim($title) !== '') {
            return $title;
        }

        $report = $this->report;

        return $report === null ? 'Untitled' : $report->name;
    }

    /**
     * How this widget draws: its own override, or the report's own choice.
     */
    public function chart(): ChartType
    {
        $override = $this->chart_type;

        if ($override !== null) {
            $type = ChartType::tryFrom($override);

            if ($type !== null) {
                return $type;
            }
        }

        $report = $this->report;

        return ChartType::tryFrom($report === null ? '' : $report->chart_type) ?? ChartType::Table;
    }

    /**
     * The grid span, clamped — a width from an old row or a hand-edited one
     * must not break the layout.
     */
    public function span(): int
    {
        return max(1, min(self::MAX_WIDTH, $this->width));
    }

    /**
     * @param  Builder<DashboardWidget>  $query
     * @return Builder<DashboardWidget>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where($query->qualifyColumn('user_id'), $user->id);
    }

    /**
     * @param  Builder<DashboardWidget>  $query
     * @return Builder<DashboardWidget>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($query->qualifyColumn('position'))->orderBy($query->qualifyColumn('id'));
    }
}
