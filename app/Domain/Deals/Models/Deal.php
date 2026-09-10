<?php

namespace App\Domain\Deals\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Concerns\TracksStageHistory;
use App\Domain\Deals\DealFields;
use App\Domain\Deals\Enums\DealCloseReason;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Concerns\ScopesByAccessLevel;
use App\Domain\Timeline\Concerns\HasTimeline;
use App\Models\User;
use Database\Factories\DealFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A piece of business being worked.
 *
 * The minimum lead conversion needs. Phase 3 adds pipelines, products, win and
 * loss reasons, stage history and the screens — this is deliberately not the
 * finished module.
 *
 * @property int $id
 * @property string $name
 * @property int $account_id
 * @property int|null $contact_id
 * @property int|null $lead_id
 * @property int|null $pipeline_id
 * @property string|null $value
 * @property Carbon|null $expected_close_date
 * @property Carbon|null $closed_at
 * @property string|null $close_reason
 * @property string|null $close_notes
 * @property string $stage
 * @property string|null $description
 * @property int $owner_id
 */
class Deal extends Model
{
    /** @use HasFactory<DealFactory> */
    use HasFactory;

    use HasTimeline;
    use RecordsActivity;
    use ScopesByAccessLevel;
    use SoftDeletes;
    use TracksStageHistory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'account_id',
        'contact_id',
        'lead_id',
        'pipeline_id',
        'value',
        'expected_close_date',
        'description',
        'owner_id',
    ];

    /**
     * Columns that share a name with a method on this model.
     *
     * Laravel decides whether a property read is a relation by looking for a
     * method of that name, so on an instance where such an attribute is missing
     * — Model::create() leaves out whatever it was not given — reading it calls
     * the method and fails. Declaring defaults keeps the key present on every
     * instance, which is the only thing that makes the pair safe.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'stage' => 'new',
        'close_reason' => null,
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'expected_close_date' => 'date',
            'closed_at' => 'datetime',
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
            'name', 'account_id', 'contact_id', 'value', 'expected_close_date',
            'pipeline_id', 'stage', 'owner_id', 'closed_at', 'close_reason',
        ];
    }

    // -- Relations ----------------------------------------------------------

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * The lead this came from, when it came from one.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The pipeline this deal is being worked along.
     *
     * @return BelongsTo<Pipeline, $this>
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    // -- Presentation --------------------------------------------------------

    public function displayName(): string
    {
        return $this->name;
    }

    public function stage(): DealStage
    {
        // getAttributeValue, not $this->stage: the method and the column
        // share a name, so on an instance that has no such attribute loaded
        // Laravel would take the property read for a relation, call this
        // method again and fail. Model::create() leaves out anything it was
        // not given, which is exactly what lead conversion does.
        return DealStage::tryFrom((string) $this->getAttributeValue('stage')) ?? DealStage::New;
    }

    /**
     * The configured stage this deal sits in, when its pipeline has one.
     *
     * A deal stores a stage *key*, resolved here against the pipeline it is on
     * — or the default pipeline when it is on none, which is what deals created
     * before pipelines existed look like. Null when nothing matches, and every
     * caller falls back to the DealStage enum rather than guessing.
     */
    public function configuredStage(): ?PipelineStage
    {
        $pipeline = $this->pipeline ?? Pipeline::default();

        return $pipeline?->stageByKey((string) $this->getAttributeValue('stage'));
    }

    /**
     * The value discounted by how likely the stage is to close.
     *
     * Probability comes from the configured stage when there is one, so an
     * administrator changing it on the pipeline changes the forecast. The enum
     * is the fallback for a database with no pipelines seeded.
     */
    public function weightedValue(): float
    {
        $stage = $this->configuredStage();
        $probability = $stage === null ? $this->stage()->probability() : $stage->probability;

        return round((float) $this->value * $probability / 100, 2);
    }

    public function isOpen(): bool
    {
        return ! $this->outcome()->isClosed();
    }

    /**
     * What the stage this deal sits in means for it.
     *
     * Derived, never stored: a flag of its own could disagree with the board,
     * and the board is the thing people look at.
     */
    public function outcome(): StageOutcome
    {
        $stage = $this->configuredStage();

        if ($stage !== null) {
            return $stage->outcome();
        }

        // No configured pipeline to ask, so fall back to the enum 2.6 shipped.
        return match (true) {
            $this->stage() === DealStage::Won => StageOutcome::Won,
            $this->stage() === DealStage::Lost => StageOutcome::Lost,
            default => StageOutcome::Open,
        };
    }

    public function isWon(): bool
    {
        return $this->outcome() === StageOutcome::Won;
    }

    public function isLost(): bool
    {
        return $this->outcome() === StageOutcome::Lost;
    }

    public function closeReason(): ?DealCloseReason
    {
        $value = $this->getAttributeValue('close_reason');

        return $value === null ? null : DealCloseReason::tryFrom((string) $value);
    }

    /**
     * How long the deal took to close, in days, or null while it is open.
     */
    public function daysToClose(): ?int
    {
        if ($this->closed_at === null || $this->created_at === null) {
            return null;
        }

        return (int) $this->created_at->diffInDays($this->closed_at);
    }

    /**
     * Whether the expected close date has gone by on a deal still open.
     */
    public function isOverdue(): bool
    {
        return $this->isOpen()
            && $this->expected_close_date !== null
            && $this->expected_close_date->isPast();
    }

    // -- Queries -------------------------------------------------------------

    /**
     * The stage keys that end a deal, one way or the other.
     *
     * Read from the configured stages so a pipeline whose closing stage is
     * called "signed" is counted, with the enum's own values always included so
     * a database with no pipelines seeded still answers correctly.
     *
     * Collected across every pipeline, which is a deliberate simplification: a
     * key that closes on one pipeline and is open on another would be treated
     * as closing. In practice these queries run alongside a pipeline filter,
     * and a key meaning two different things is a configuration mistake.
     *
     * @return array<int, string>
     */
    public static function closingStageKeys(?StageOutcome $outcome = null): array
    {
        $query = PipelineStage::query();

        $query->when(
            $outcome === null,
            fn (Builder $stages) => $stages->where('outcome', '!=', StageOutcome::Open->value),
            fn (Builder $stages) => $stages->where('outcome', $outcome?->value)
        );

        /** @var array<int, string> $configured */
        $configured = $query->pluck('key')->all();

        $fallback = match ($outcome) {
            StageOutcome::Won => [DealStage::Won->value],
            StageOutcome::Lost => [DealStage::Lost->value],
            default => [DealStage::Won->value, DealStage::Lost->value],
        };

        return array_values(array_unique([...$configured, ...$fallback]));
    }

    /**
     * @param  Builder<Deal>  $query
     * @return Builder<Deal>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn($query->qualifyColumn('stage'), static::closingStageKeys());
    }

    /**
     * @param  Builder<Deal>  $query
     * @return Builder<Deal>
     */
    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('stage'), static::closingStageKeys());
    }

    /**
     * @param  Builder<Deal>  $query
     * @return Builder<Deal>
     */
    public function scopeWithOutcome(Builder $query, StageOutcome $outcome): Builder
    {
        if ($outcome === StageOutcome::Open) {
            return $query->open();
        }

        return $query->whereIn($query->qualifyColumn('stage'), static::closingStageKeys($outcome));
    }

    /**
     * @param  Builder<Deal>  $query
     * @return Builder<Deal>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        if ($term === '') {
            return $query;
        }

        // The same columns DealFields::searchColumns() gives the list screen,
        // and deliberately only columns on this table. The data-view kit builds
        // the list's search from those columns alone, so reaching into the
        // account from here would make a queued export match rows the list
        // never showed — the drift .ai/rules/accounts.md warns about.
        return $query->where(function (Builder $inner) use ($term) {
            foreach (DealFields::searchColumns() as $column) {
                $inner->orWhere($inner->qualifyColumn($column), 'like', '%'.$term.'%');
            }
        });
    }
}
