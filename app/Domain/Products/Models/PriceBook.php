<?php

namespace App\Domain\Products\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use Database\Factories\PriceBookFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A set of prices that overrides the catalogue.
 *
 * A book can be dated, so a promotional one stops applying on its own rather
 * than needing somebody to remember to switch it off. `appliesOn()` is the one
 * place that decides whether a book counts today, and `PriceResolver` is its
 * only caller — a screen that answered the question itself would eventually
 * answer it differently.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property bool $is_default
 * @property bool $is_active
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 */
class PriceBook extends Model
{
    /** @use HasFactory<PriceBookFactory> */
    use HasFactory;

    use RecordsActivity;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'is_default',
        'is_active',
        'valid_from',
        'valid_to',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_default' => false,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    /**
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return ['name', 'is_default', 'is_active', 'valid_from', 'valid_to'];
    }

    /**
     * @return HasMany<PriceBookEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(PriceBookEntry::class);
    }

    /**
     * Whether this book counts on a given day.
     *
     * An inactive book never counts. A dated one counts inside its window,
     * inclusive at both ends — somebody writing "valid to the 31st" means the
     * 31st is included, and the alternative surprises them on the last day of
     * every promotion.
     */
    public function appliesOn(?Carbon $day = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $day = ($day ?? Carbon::now())->copy()->startOfDay();

        if ($this->valid_from !== null && $day->lt($this->valid_from->copy()->startOfDay())) {
            return false;
        }

        return $this->valid_to === null || $day->lte($this->valid_to->copy()->startOfDay());
    }

    /**
     * The book prices fall back to when a document names none.
     *
     * Null when there is no default, which is a real state: a fresh
     * installation has no price books at all, and everything resolves to the
     * catalogue price.
     */
    public static function default(): ?self
    {
        return self::query()->where('is_default', true)->first();
    }

    /**
     * @param  Builder<PriceBook>  $query
     * @return Builder<PriceBook>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }
}
