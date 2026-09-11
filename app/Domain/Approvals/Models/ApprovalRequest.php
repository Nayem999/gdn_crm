<?php

namespace App\Domain\Approvals\Models;

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\WorkflowModules;
use Database\Factories\ApprovalRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One thing somebody is being asked to agree to.
 *
 * A request holds the outcome; the levels hold the people asked, in turn. It
 * carries its own copy of the workflow's name and a written summary of what is
 * being approved, because both are read long after the workflow they came from
 * may have been edited or deleted — and an approval that cannot say what was
 * agreed to is not an audit trail.
 *
 * @property int $id
 * @property int|null $workflow_id
 * @property int|null $workflow_run_id
 * @property int|null $workflow_action_id
 * @property string $workflow_name
 * @property string $module
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string $summary
 * @property string $status
 * @property int $current_level
 * @property int $resume_from_position
 * @property string|null $decision_comment
 * @property Carbon $requested_at
 * @property Carbon|null $completed_at
 */
class ApprovalRequest extends Model
{
    /** @use HasFactory<ApprovalRequestFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workflow_id',
        'workflow_run_id',
        'workflow_action_id',
        'workflow_name',
        'module',
        'subject_type',
        'subject_id',
        'summary',
        'status',
        'current_level',
        'resume_from_position',
        'decision_comment',
        'requested_at',
        'completed_at',
    ];

    /**
     * `status()` and `module()` are named after their columns. See
     * .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ApprovalStatus::Waiting->value,
        'module' => '',
        'current_level' => 0,
        'resume_from_position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'current_level' => 'integer',
            'resume_from_position' => 'integer',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ApprovalLevel, $this>
     */
    public function levels(): HasMany
    {
        return $this->hasMany(ApprovalLevel::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return BelongsTo<WorkflowRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function status(): ApprovalStatus
    {
        return ApprovalStatus::tryFrom((string) $this->getAttributeValue('status'))
            ?? ApprovalStatus::Waiting;
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
     * The level being asked now, or null when the request is settled.
     */
    public function currentLevel(): ?ApprovalLevel
    {
        if (! $this->status()->isOpen()) {
            return null;
        }

        return $this->levels()
            ->where('position', $this->current_level)
            ->where('status', ApprovalStatus::Waiting->value)
            ->first();
    }

    /**
     * Whether this person is the one being asked right now.
     *
     * Deliberately "right now" rather than "somewhere in the chain": a level
     * three approver answering before level one would skip the people whose
     * agreement the chain exists to collect.
     */
    public function isAwaiting(int $userId): bool
    {
        return $this->currentLevel()?->approver_id === $userId;
    }

    /**
     * @param  Builder<ApprovalRequest>  $query
     * @return Builder<ApprovalRequest>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ApprovalStatus::Waiting->value);
    }

    /**
     * Requests waiting on this person's answer.
     *
     * @param  Builder<ApprovalRequest>  $query
     * @return Builder<ApprovalRequest>
     */
    public function scopeAwaiting(Builder $query, int $userId): Builder
    {
        return $query->open()->whereHas('levels', function (Builder $level) use ($userId): void {
            $level->where('approver_id', $userId)
                ->where('status', ApprovalStatus::Waiting->value)
                // The level being asked now, not any level they appear on.
                ->whereColumn('position', 'approval_requests.current_level');
        });
    }
}
