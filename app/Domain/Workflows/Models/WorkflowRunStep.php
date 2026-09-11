<?php

namespace App\Domain\Workflows\Models;

use App\Domain\Workflows\Enums\WorkflowActionType;
use App\Domain\Workflows\Enums\WorkflowRunStatus;
use Database\Factories\WorkflowRunStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one step of one run did.
 *
 * Keeps its own copy of the action's type for the same reason a run keeps the
 * workflow's name: the step that ran is a fact about the past, and editing the
 * workflow afterwards must not rewrite it.
 *
 * @property int $id
 * @property int $workflow_run_id
 * @property int|null $workflow_action_id
 * @property string $action_type
 * @property int $position
 * @property string $status
 * @property string|null $message
 * @property array<string, mixed>|null $result
 * @property int $attempts
 * @property int|null $duration_ms
 */
class WorkflowRunStep extends Model
{
    /** @use HasFactory<WorkflowRunStepFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workflow_run_id',
        'workflow_action_id',
        'action_type',
        'position',
        'status',
        'message',
        'result',
        'attempts',
        'duration_ms',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => WorkflowRunStatus::Pending->value,
        'action_type' => WorkflowActionType::UpdateField->value,
        'position' => 0,
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'position' => 'integer',
            'attempts' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<WorkflowRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }

    /**
     * The step's definition, if it has not since been deleted.
     *
     * @return BelongsTo<WorkflowAction, $this>
     */
    public function action(): BelongsTo
    {
        return $this->belongsTo(WorkflowAction::class, 'workflow_action_id');
    }

    public function status(): WorkflowRunStatus
    {
        return WorkflowRunStatus::tryFrom((string) $this->getAttributeValue('status'))
            ?? WorkflowRunStatus::Pending;
    }

    public function actionType(): WorkflowActionType
    {
        return WorkflowActionType::tryFrom((string) $this->getAttributeValue('action_type'))
            ?? WorkflowActionType::UpdateField;
    }
}
