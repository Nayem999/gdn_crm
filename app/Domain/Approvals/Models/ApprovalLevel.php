<?php

namespace App\Domain\Approvals\Models;

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Models\User;
use Database\Factories\ApprovalLevelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person in an approval chain, and what they said.
 *
 * @property int $id
 * @property int $approval_request_id
 * @property int $position
 * @property int|null $approver_id
 * @property string $status
 * @property Carbon|null $due_at
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $comment
 * @property Carbon|null $escalated_at
 */
class ApprovalLevel extends Model
{
    /** @use HasFactory<ApprovalLevelFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'approval_request_id',
        'position',
        'approver_id',
        'status',
        'due_at',
        'decided_by',
        'decided_at',
        'comment',
        'escalated_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ApprovalStatus::Waiting->value,
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'due_at' => 'datetime',
            'decided_at' => 'datetime',
            'escalated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function status(): ApprovalStatus
    {
        return ApprovalStatus::tryFrom((string) $this->getAttributeValue('status'))
            ?? ApprovalStatus::Waiting;
    }

    /**
     * Whether this level has waited longer than it was given.
     *
     * A level with no `due_at` never runs out: waiting indefinitely is a real
     * choice for an approval nobody should be able to let lapse by ignoring it.
     */
    public function isOverdue(?Carbon $now = null): bool
    {
        return $this->status()->isOpen()
            && $this->due_at !== null
            && $this->due_at->lte($now ?? Carbon::now());
    }

    /**
     * @param  Builder<ApprovalLevel>  $query
     * @return Builder<ApprovalLevel>
     */
    public function scopeOverdue(Builder $query, ?Carbon $now = null): Builder
    {
        return $query
            ->where($query->qualifyColumn('status'), ApprovalStatus::Waiting->value)
            ->whereNotNull($query->qualifyColumn('due_at'))
            ->where($query->qualifyColumn('due_at'), '<=', $now ?? Carbon::now());
    }
}
