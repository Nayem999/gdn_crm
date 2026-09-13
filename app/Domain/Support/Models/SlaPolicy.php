<?php

namespace App\Domain\Support\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Support\Enums\TicketPriority;
use Database\Factories\SlaPolicyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What we promise, and how long we have to do it.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property bool $is_default
 * @property bool $is_active
 * @property int $warn_at_percent
 * @property-read Collection<int, SlaTarget> $targets
 */
class SlaPolicy extends Model
{
    /** @use HasFactory<SlaPolicyFactory> */
    use HasFactory;

    use RecordsActivity;
    use SoftDeletes;

    /**
     * The warning point a policy gets when nobody chooses one.
     */
    public const DEFAULT_WARN_PERCENT = 80;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'description', 'is_default', 'is_active', 'warn_at_percent'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_default' => false,
        'is_active' => true,
        'warn_at_percent' => self::DEFAULT_WARN_PERCENT,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'warn_at_percent' => 'integer',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function activityAttributes(): array
    {
        return ['name', 'description', 'is_default', 'is_active', 'warn_at_percent'];
    }

    /**
     * @return HasMany<SlaTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(SlaTarget::class);
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * What this policy promises at one priority, or null when it promises
     * nothing there.
     */
    public function targetFor(TicketPriority $priority): ?SlaTarget
    {
        return $this->targets->firstWhere('priority', $priority->value);
    }

    /**
     * The fraction of a target at which a warning goes out, as a number between
     * 0 and 1. Clamped, because a percentage outside that range would put the
     * warning after the breach or before the clock starts.
     */
    public function warnAtFraction(): float
    {
        return max(1, min(99, $this->warn_at_percent)) / 100;
    }

    /**
     * @param  Builder<SlaPolicy>  $query
     * @return Builder<SlaPolicy>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }
}
