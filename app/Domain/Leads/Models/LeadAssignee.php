<?php

namespace App\Domain\Leads\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Database\Factories\LeadAssigneeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * One person's stake in one lead.
 *
 * Several of these can exist for the same lead at once, and every one of them
 * is a full, present-tense assignee — this is not a queue with one active row.
 * `priority` only orders the escalation ladder `leads:escalate-assignments`
 * sweeps (see that command); a row with a null priority sits outside the
 * ladder entirely and is never touched by it, but is exactly as able to open,
 * edit or work the lead as one with priority 1.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $lead_id
 * @property int $user_id
 * @property int|null $priority
 * @property Carbon $assigned_at
 * @property Carbon|null $escalated_at
 */
class LeadAssignee extends Pivot
{
    use BelongsToTenant;

    /** @use HasFactory<LeadAssigneeFactory> */
    use HasFactory;

    /**
     * Pivot guesses its table name as the *singular* of the class name
     * (`role_user`-style convention), not the plural an ordinary model would
     * — this is a real, named table, not a bare join table, so that guess is
     * wrong for it.
     */
    protected $table = 'lead_assignees';

    /**
     * Pivot defaults this to false, on the assumption of a composite key with
     * no id column of its own. This table has a real one — see the migration
     * — because a plain pivot cannot carry the escalation state a priority
     * needs a place to live.
     */
    public $incrementing = true;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'lead_id',
        'user_id',
        'priority',
        'assigned_at',
        'escalated_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'assigned_at' => 'datetime',
            'escalated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOnLadder(): bool
    {
        return $this->priority !== null;
    }
}
