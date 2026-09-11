<?php

namespace App\Domain\Workflows\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Shared\Filters\FilterGroup;
use App\Domain\Workflows\Enums\WorkflowTrigger;
use App\Domain\Workflows\WorkflowModules;
use App\Models\User;
use Database\Factories\WorkflowFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One automation: what fires it, what it checks, and what it does.
 *
 * The condition tree is stored in the filter builder's array shape and handed
 * back to `FilterGroup`, which is the same thing `LeadScoringRule` does and for
 * the same reason: a workflow that says "estimated value is at least 5000"
 * means exactly what the filter chip with that condition means, because 5.3
 * evaluates it with the same applier.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $module
 * @property string $trigger_event
 * @property string|null $trigger_field
 * @property int|null $date_offset_minutes
 * @property array<string, mixed> $conditions
 * @property bool $is_active
 * @property int $position
 * @property bool $run_once_per_record
 * @property int|null $created_by
 * @property int $run_count
 * @property Carbon|null $last_run_at
 */
class Workflow extends Model
{
    /** @use HasFactory<WorkflowFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'module',
        'trigger_event',
        'trigger_field',
        'date_offset_minutes',
        'conditions',
        'is_active',
        'position',
        'run_once_per_record',
        'created_by',
    ];

    /**
     * Columns that share a name with a method here, plus the condition tree.
     *
     * `module`, `conditions` and `trigger_event` all have accessors named after
     * them, so each needs a default or a partially-created instance reads the
     * method and Laravel takes it for a relation — see
     * .ai/rules/models-name-collisions.md.
     *
     * The empty filter group is the default for `conditions` because a null
     * tree and an empty one would be two spellings of "no conditions", and
     * something would eventually check only one of them.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'module' => '',
        'trigger_event' => WorkflowTrigger::RecordCreated->value,
        'conditions' => '{"match":"all","conditions":[],"groups":[]}',
        'is_active' => false,
        'position' => 0,
        'run_once_per_record' => false,
        'run_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'is_active' => 'boolean',
            'position' => 'integer',
            'run_once_per_record' => 'boolean',
            'date_offset_minutes' => 'integer',
            'run_count' => 'integer',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        // The definition, not the counters: `run_count` changes on every
        // firing, and logging that would bury the edits somebody actually
        // wants to find in the audit trail.
        return ['name', 'module', 'trigger_event', 'trigger_field', 'conditions', 'is_active', 'position'];
    }

    /**
     * @return HasMany<WorkflowAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(WorkflowAction::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<WorkflowRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function trigger(): WorkflowTrigger
    {
        return WorkflowTrigger::tryFrom((string) $this->getAttributeValue('trigger_event'))
            ?? WorkflowTrigger::RecordCreated;
    }

    public function module(): string
    {
        return (string) $this->getAttributeValue('module');
    }

    public function moduleLabel(): string
    {
        return WorkflowModules::label($this->module());
    }

    /**
     * The condition tree, rebuilt from storage.
     *
     * Unusable rows are dropped by `FilterGroup::fromArray()` on the way, the
     * same as they are for a saved view — a half-filled condition is ignored
     * rather than matching everything.
     */
    public function conditions(): FilterGroup
    {
        $stored = $this->getAttributeValue('conditions');

        return FilterGroup::fromArray(is_array($stored) ? $stored : []);
    }

    /**
     * Whether this definition could actually do something.
     *
     * A workflow with no active actions is not broken — somebody is part way
     * through writing it — but it must never be treated as ready to run.
     */
    public function isRunnable(): bool
    {
        if (! $this->is_active || ! WorkflowModules::has($this->module())) {
            return false;
        }

        if ($this->trigger()->needsField() && $this->trigger_field === null) {
            return false;
        }

        return $this->actions()->where('is_active', true)->exists();
    }

    /**
     * @param  Builder<Workflow>  $query
     * @return Builder<Workflow>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * The workflows that should be considered when something happens to a
     * record, in the order they run.
     *
     * Ordered explicitly by position: two workflows that both set a field would
     * otherwise race, and the winner would be whatever the database happened to
     * return first.
     *
     * @param  Builder<Workflow>  $query
     * @return Builder<Workflow>
     */
    public function scopeListeningFor(Builder $query, string $module, WorkflowTrigger $trigger): Builder
    {
        return $query->active()
            ->where($query->qualifyColumn('module'), $module)
            ->where($query->qualifyColumn('trigger_event'), $trigger->value)
            ->orderBy($query->qualifyColumn('position'))
            ->orderBy($query->qualifyColumn('id'));
    }
}
