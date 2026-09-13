<?php

namespace App\Domain\Reports\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Reports\ReportDefinition;
use App\Domain\Reports\ReportSource;
use App\Domain\Reports\ReportSources;
use App\Models\User;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A saved question.
 *
 * Not `ScopesByAccessLevel`: a report is a question, not a record about a
 * customer, and the records it aggregates are scoped when it *runs* — by the
 * viewer, not by the author. Two people running the same shared report see
 * different numbers, and that is correct. What is scoped here is whether the
 * report itself is visible: your own, plus everything shared.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $source
 * @property array<string, mixed> $definition
 * @property string $chart_type
 * @property int|null $owner_id
 * @property bool $is_shared
 * @property bool $is_standard
 * @property string|null $slug
 * @property Carbon|null $last_run_at
 */
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    use RecordsActivity;
    use SoftDeletes;

    /**
     * `is_standard` and `slug` are absent: the seeder owns them, and a form
     * that could set either would let somebody claim a built-in's identity.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'source',
        'definition',
        'chart_type',
        'owner_id',
        'is_shared',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'chart_type' => 'table',
        'is_shared' => false,
        'is_standard' => false,
        // `definition` shares its name with the method below. Without a
        // default, reading the property on an instance that was never given the
        // column turns the read into a relation lookup and calls the method,
        // which reads the property — see .ai/rules/models-name-collisions.md.
        // The raw stored value, because the attribute is cast to array.
        'definition' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'is_shared' => 'boolean',
            'is_standard' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * The definition is on the list: "who changed what this report asks" is
     * the question somebody has when a figure moves.
     *
     * @return array<int, string>
     */
    protected function activityAttributes(): array
    {
        return ['name', 'source', 'definition', 'chart_type', 'is_shared', 'owner_id'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function displayName(): string
    {
        return $this->name;
    }

    /**
     * The question, rebuilt from the stored keys.
     */
    public function definition(): ReportDefinition
    {
        $state = $this->definition;

        // The stored source column wins over anything inside the JSON: it is
        // what the list and the policy read, and two sources of truth for the
        // same fact is how a report ends up filed under one module and run
        // against another.
        return ReportDefinition::fromArray([...$state, 'source' => $this->source]);
    }

    public function reportSource(): ?ReportSource
    {
        return ReportSources::find($this->source);
    }

    /**
     * Whether the module this report is about is one the viewer may see.
     *
     * Separate from whether they may see the report: a shared report about
     * deals is listed for everybody, and refuses to run for somebody with no
     * `deals.view`. Hiding it entirely would be worse — a report that appears
     * for one colleague and not another looks like a fault.
     */
    public function runnableBy(User $user): bool
    {
        return $this->reportSource()?->visibleTo($user) === true;
    }

    /**
     * Reports this person may see: their own, and everything shared.
     *
     * @param  Builder<Report>  $query
     * @return Builder<Report>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $inner) use ($user) {
            $inner->where($inner->qualifyColumn('is_shared'), true)
                ->orWhere($inner->qualifyColumn('owner_id'), $user->id);
        });
    }

    /**
     * @param  Builder<Report>  $query
     * @return Builder<Report>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($term) {
            $inner->where($inner->qualifyColumn('name'), 'like', '%'.$term.'%')
                ->orWhere($inner->qualifyColumn('description'), 'like', '%'.$term.'%');
        });
    }
}
