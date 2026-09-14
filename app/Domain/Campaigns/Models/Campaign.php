<?php

namespace App\Domain\Campaigns\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\CampaignType;
use App\Domain\Contacts\Models\Contact;
use App\Domain\CustomFields\Concerns\HasCustomFields;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Concerns\ScopesByAccessLevel;
use App\Domain\Timeline\Concerns\HasTimeline;
use App\Models\User;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One piece of marketing the company spends money on.
 *
 * This is the CRM's own record, not Meta's. A Meta campaign is linked to one of
 * these in 12.7, and so is every other channel the company runs — email, an
 * exhibition stand, a referral scheme — which is the point: cost per lead is
 * only a useful number when every cost is in the same place as every lead.
 *
 * **`actual_cost` is typed in, not synced.** Meta's spend is added to it when a
 * linked Meta campaign is read, but a campaign that runs nowhere near Meta
 * still costs money, and a column that could only be written by an integration
 * would make the whole module useless to the half of marketing that is people
 * and stands.
 *
 * @property int $id
 * @property string $name
 * @property string $type
 * @property string $status
 * @property string|null $description
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property string|null $budget
 * @property string|null $actual_cost
 * @property string|null $expected_revenue
 * @property string|null $code
 * @property int $owner_id
 */
class Campaign extends Model
{
    use HasCustomFields;

    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    use HasTimeline;
    use RecordsActivity;
    use ScopesByAccessLevel;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'type',
        'status',
        'description',
        'start_date',
        'end_date',
        'budget',
        'actual_cost',
        'expected_revenue',
        'code',
        'owner_id',
    ];

    /**
     * `type()` and `status()` are named after their columns, so both need a
     * default or a partially-created instance reads the method and Laravel
     * takes it for a relation. See .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => CampaignType::Other->value,
        'status' => CampaignStatus::Planned->value,
        // budget() shares its name with this column too. Null is the column's
        // own default; what matters is that the key is always present.
        'budget' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            // Decimal strings rather than floats: a float cannot hold 0.1, and
            // a budget that is out by a hundredth is one somebody has to
            // explain.
            'budget' => 'decimal:2',
            'actual_cost' => 'decimal:2',
            'expected_revenue' => 'decimal:2',
        ];
    }

    /**
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return ['name', 'type', 'status', 'start_date', 'end_date', 'budget', 'actual_cost', 'expected_revenue', 'code', 'owner_id'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * @return HasMany<Deal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function type(): CampaignType
    {
        return CampaignType::tryFrom((string) $this->getAttributeValue('type')) ?? CampaignType::Other;
    }

    public function status(): CampaignStatus
    {
        return CampaignStatus::tryFrom((string) $this->getAttributeValue('status')) ?? CampaignStatus::Planned;
    }

    /**
     * What has been spent, which is the typed figure and not the budget.
     *
     * Zero rather than null when nothing has been recorded: a campaign with no
     * cost yet has cost nothing, and null would make every derived figure null
     * too.
     */
    public function cost(): float
    {
        return (float) ($this->getAttributeValue('actual_cost') ?? 0);
    }

    public function budget(): ?float
    {
        $budget = $this->getAttributeValue('budget');

        return $budget === null ? null : (float) $budget;
    }

    /**
     * How much of the budget is gone, or null when there is no budget to be
     * over. Over 100 is a real answer, not a cap: a campaign that has overspent
     * should read as having overspent.
     */
    public function budgetUsedPercent(): ?float
    {
        $budget = $this->budget();

        if ($budget === null || $budget <= 0.0) {
            return null;
        }

        return round(($this->cost() / $budget) * 100, 1);
    }

    /**
     * Whether the campaign is within its own dates today.
     *
     * Separate from the status, because the two disagree in the way that
     * matters: a campaign somebody forgot to mark Completed is still "active"
     * and long finished, and a list that could not tell them apart is a list
     * nobody trusts.
     */
    public function isWithinDates(?Carbon $on = null): bool
    {
        $on = $on ?? Carbon::today();

        if ($this->start_date !== null && $on->lt($this->start_date)) {
            return false;
        }

        return ! ($this->end_date !== null && $on->gt($this->end_date));
    }

    /**
     * Campaigns somebody might reasonably attribute a new record to: running
     * now, or finished recently enough that a lead arriving today could still
     * have come from one.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAttributable(Builder $query, int $graceDays = 30): Builder
    {
        return $query
            ->whereIn('status', [CampaignStatus::Active->value, CampaignStatus::Paused->value, CampaignStatus::Completed->value])
            ->where(function (Builder $query) use ($graceDays) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', Carbon::today()->subDays($graceDays));
            });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($term) {
            $query->where('campaigns.name', 'like', '%'.$term.'%')
                ->orWhere('campaigns.code', 'like', '%'.$term.'%')
                ->orWhere('campaigns.description', 'like', '%'.$term.'%');
        });
    }
}
