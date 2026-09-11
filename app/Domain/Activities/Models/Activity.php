<?php

namespace App\Domain\Activities\Models;

use App\Domain\Activities\ActivityFields;
use App\Domain\Activities\DTOs\RecurrenceRule;
use App\Domain\Activities\Enums\ActivityPriority;
use App\Domain\Activities\Enums\ActivityStatus;
use App\Domain\Activities\Enums\ActivityType;
use App\Domain\Activities\Enums\RecurrenceFrequency;
use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\CustomFields\Concerns\HasCustomFields;
use App\Domain\Shared\Concerns\ScopesByAccessLevel;
use App\Models\User;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A task, call or meeting.
 *
 * **This is not the audit trail.** `Spatie\Activitylog\Models\Activity` is the
 * audit entry, and every audited model — including this one — carries an
 * `activities()` relation that returns those. The relation pointing the other
 * way, from a lead or a deal to its tasks and calls, is therefore called
 * `scheduledActivities()`. See .ai/rules/activities.md.
 *
 * @property int $id
 * @property string $type
 * @property string $subject
 * @property string|null $description
 * @property string $status
 * @property int $priority
 * @property Carbon $due_at
 * @property bool $all_day
 * @property int|null $duration_minutes
 * @property string|null $location
 * @property Carbon|null $completed_at
 * @property string|null $completion_notes
 * @property int|null $reminder_minutes_before
 * @property Carbon|null $reminder_sent_at
 * @property string|null $recurrence_frequency
 * @property int $recurrence_interval
 * @property Carbon|null $recurrence_until
 * @property int|null $recurrence_count
 * @property int|null $recurrence_parent_id
 * @property string|null $related_type
 * @property int|null $related_id
 * @property int $owner_id
 * @property int|null $created_by_id
 */
class Activity extends Model
{
    use HasCustomFields;

    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    use RecordsActivity;
    use ScopesByAccessLevel;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'subject',
        'description',
        'priority',
        'due_at',
        'all_day',
        'duration_minutes',
        'location',
        'reminder_minutes_before',
        'recurrence_frequency',
        'recurrence_interval',
        'recurrence_until',
        'recurrence_count',
        'related_type',
        'related_id',
        'owner_id',
        'created_by_id',
    ];

    /**
     * Columns that share a name with a method on this model.
     *
     * Laravel decides whether a property read is a relation by looking for a
     * method of that name, so on an instance where the attribute is missing the
     * read calls the method and fails. Declaring defaults keeps the key present
     * however the record was made — see .ai/rules/models-name-collisions.md.
     *
     * `status` is also absent from $fillable on purpose: the complete, reopen
     * and cancel actions own it, the way MoveDealStageAction owns a deal's
     * stage.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'task',
        'status' => 'open',
        'priority' => 2,
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'all_day' => 'boolean',
            'completed_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'recurrence_until' => 'date',
            'duration_minutes' => 'integer',
            'priority' => 'integer',
            'reminder_minutes_before' => 'integer',
            'recurrence_interval' => 'integer',
            'recurrence_count' => 'integer',
        ];
    }

    /**
     * An explicit allowlist, never logAll().
     *
     * @return array<int, string>
     */
    protected function activityAttributes(): array
    {
        return [
            'type', 'subject', 'status', 'priority', 'due_at', 'all_day',
            'duration_minutes', 'location', 'completed_at', 'owner_id',
            'related_type', 'related_id', 'recurrence_frequency',
        ];
    }

    /**
     * Distinguishes the CRM record from the audit entry in the audit viewer,
     * where both would otherwise be listed as "Activity".
     */
    public static function activitySubjectLabel(): string
    {
        return 'Task, call or meeting';
    }

    // -- Relations ----------------------------------------------------------

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * The lead, contact, account or deal this is about, when it is about one.
     *
     * @return MorphTo<Model, $this>
     */
    public function related(): MorphTo
    {
        return $this->morphTo('related');
    }

    /**
     * The series this occurrence belongs to.
     *
     * @return BelongsTo<Activity, $this>
     */
    public function recurrenceParent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'recurrence_parent_id');
    }

    /**
     * The occurrences generated from this series, oldest first.
     *
     * @return HasMany<Activity, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(self::class, 'recurrence_parent_id')->orderBy('due_at');
    }

    // -- Presentation --------------------------------------------------------

    public function displayName(): string
    {
        return $this->subject;
    }

    /**
     * getAttributeValue, not $this->type: the method and the column share a
     * name, so a property read on an instance without that attribute loaded
     * would be taken for a relation and call this method again.
     */
    public function type(): ActivityType
    {
        return ActivityType::tryFrom((string) $this->getAttributeValue('type')) ?? ActivityType::Task;
    }

    public function status(): ActivityStatus
    {
        return ActivityStatus::tryFrom((string) $this->getAttributeValue('status')) ?? ActivityStatus::Open;
    }

    public function priority(): ActivityPriority
    {
        return ActivityPriority::tryFrom((int) $this->getAttributeValue('priority')) ?? ActivityPriority::Normal;
    }

    public function isOpen(): bool
    {
        return $this->status()->isOpen();
    }

    public function isCompleted(): bool
    {
        return $this->status() === ActivityStatus::Completed;
    }

    public function isCancelled(): bool
    {
        return $this->status() === ActivityStatus::Cancelled;
    }

    /**
     * Past its due date and still open.
     *
     * An all-day activity is not overdue until the day itself has gone: a task
     * due today at 00:00 is not late at lunchtime.
     */
    public function isOverdue(): bool
    {
        if (! $this->isOpen()) {
            return false;
        }

        $deadline = $this->all_day ? $this->due_at->copy()->endOfDay() : $this->due_at;

        return $deadline->isPast();
    }

    /**
     * When the reminder for this activity should go out, or null when it has
     * none configured.
     */
    public function remindAt(): ?Carbon
    {
        if ($this->reminder_minutes_before === null) {
            return null;
        }

        return $this->due_at->copy()->subMinutes($this->reminder_minutes_before);
    }

    /**
     * The due date as people read it — a time only when there is one to show.
     */
    public function dueLabel(): string
    {
        return $this->all_day
            ? $this->due_at->format('j M Y')
            : $this->due_at->format('j M Y, H:i');
    }

    /**
     * The repeat rule, or null when this is a one-off or an occurrence of a
     * series (only the master carries the rule).
     */
    public function recurrence(): ?RecurrenceRule
    {
        $frequency = RecurrenceFrequency::tryFrom((string) $this->recurrence_frequency);

        if ($frequency === null) {
            return null;
        }

        return new RecurrenceRule(
            frequency: $frequency,
            interval: max(1, (int) $this->recurrence_interval),
            until: $this->recurrence_until,
            count: $this->recurrence_count,
        );
    }

    /**
     * Whether this row is the series definition. An occurrence never carries a
     * rule of its own, which is what stops a generated row generating more.
     */
    public function isSeriesMaster(): bool
    {
        return $this->recurrence_parent_id === null && $this->recurrence() !== null;
    }

    public function isOccurrence(): bool
    {
        return $this->recurrence_parent_id !== null;
    }

    // -- Queries -------------------------------------------------------------

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ActivityStatus::Open->value);
    }

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function scopeWithStatus(Builder $query, ActivityStatus $status): Builder
    {
        return $query->where($query->qualifyColumn('status'), $status->value);
    }

    /**
     * Open and past its deadline, with the same all-day allowance isOverdue()
     * makes, so the list and the record agree about what is late.
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->where(function (Builder $inner) {
            $inner->where(function (Builder $timed) {
                $timed->where($timed->qualifyColumn('all_day'), false)
                    ->where($timed->qualifyColumn('due_at'), '<', now());
            })->orWhere(function (Builder $allDay) {
                $allDay->where($allDay->qualifyColumn('all_day'), true)
                    ->where($allDay->qualifyColumn('due_at'), '<', now()->startOfDay());
            });
        });
    }

    /**
     * Everything due inside a window, which is what the calendar in 3.6 asks.
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function scopeDueBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween($query->qualifyColumn('due_at'), [$from, $to]);
    }

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function scopeForRecord(Builder $query, Model $record): Builder
    {
        return $query
            ->where($query->qualifyColumn('related_type'), $record->getMorphClass())
            ->where($query->qualifyColumn('related_id'), $record->getKey());
    }

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        if ($term === '') {
            return $query;
        }

        // The same columns the list screen searches, and deliberately only
        // columns on this table — see .ai/rules/accounts.md on why a model scope
        // that reaches into a relation makes a queued export disagree with the
        // list it came from.
        return $query->where(function (Builder $inner) use ($term) {
            foreach (ActivityFields::searchColumns() as $column) {
                $inner->orWhere($inner->qualifyColumn($column), 'like', '%'.$term.'%');
            }
        });
    }
}
