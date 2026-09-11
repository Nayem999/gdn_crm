<?php

namespace App\Domain\Workflows\Models;

use App\Domain\Workflows\Enums\WorkflowRunStatus;
use App\Domain\Workflows\Enums\WorkflowTrigger;
use Database\Factories\WorkflowRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One firing of one workflow.
 *
 * The log outlives the definition: `workflow_id` nulls out when a workflow is
 * deleted and `workflow_name` keeps a copy, so history reads as something
 * rather than as a page of blanks. Nothing here is audit-logged — this *is* the
 * record of what happened.
 *
 * @property int $id
 * @property int|null $workflow_id
 * @property string $workflow_name
 * @property string $module
 * @property string $trigger_event
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string $status
 * @property int|null $resume_from_position
 * @property string|null $dedupe_key
 * @property string|null $message
 * @property array<string, mixed>|null $context
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 */
class WorkflowRun extends Model
{
    /** @use HasFactory<WorkflowRunFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workflow_id',
        'workflow_name',
        'module',
        'trigger_event',
        'subject_type',
        'subject_id',
        'status',
        'resume_from_position',
        'dedupe_key',
        'message',
        'context',
        'started_at',
        'finished_at',
        'duration_ms',
    ];

    /**
     * `status()`, `module()` and `trigger()` share their names with columns.
     * See .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => WorkflowRunStatus::Pending->value,
        'module' => '',
        'trigger_event' => WorkflowTrigger::RecordCreated->value,
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'resume_from_position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return HasMany<WorkflowRunStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowRunStep::class)->orderBy('position')->orderBy('id');
    }

    /**
     * The record this ran for, if there was one and it still exists.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function status(): WorkflowRunStatus
    {
        return WorkflowRunStatus::tryFrom((string) $this->getAttributeValue('status'))
            ?? WorkflowRunStatus::Pending;
    }

    public function module(): string
    {
        return (string) $this->getAttributeValue('module');
    }

    public function trigger(): WorkflowTrigger
    {
        return WorkflowTrigger::tryFrom((string) $this->getAttributeValue('trigger_event'))
            ?? WorkflowTrigger::RecordCreated;
    }

    /**
     * @param  Builder<WorkflowRun>  $query
     * @return Builder<WorkflowRun>
     */
    public function scopeWithStatus(Builder $query, WorkflowRunStatus $status): Builder
    {
        return $query->where($query->qualifyColumn('status'), $status->value);
    }

    /**
     * @param  Builder<WorkflowRun>  $query
     * @return Builder<WorkflowRun>
     */
    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query
            ->where($query->qualifyColumn('subject_type'), $subject->getMorphClass())
            ->where($query->qualifyColumn('subject_id'), $subject->getKey());
    }
}
