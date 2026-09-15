<?php

namespace App\Domain\Meta\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Meta\Enums\MetaAdLevel;
use App\Models\User;
use Database\Factories\MetaCampaignFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A campaign as Meta has it, and the CRM campaign it belongs to.
 *
 * The link is the whole point of the row. Meta knows what was spent and this
 * application knows what was earned, and neither figure means anything without
 * the other — cost per lead is a division across the two systems, and the link
 * is where the division becomes possible.
 *
 * It is **one-to-one**, enforced at the database rather than hoped for: two Meta
 * campaigns pointed at one CRM campaign would double its spend in every derived
 * figure, and nothing on a screen would say why the number looked wrong.
 *
 * Linking writes nothing to `campaigns.actual_cost`. That column is typed in by
 * a person and belongs to them — see .ai/rules/ads.md.
 *
 * @property int $id
 * @property string $meta_campaign_id
 * @property string $ad_account_id
 * @property string $name
 * @property string|null $objective
 * @property string|null $status
 * @property string|null $effective_status
 * @property string|null $daily_budget
 * @property string|null $lifetime_budget
 * @property Carbon|null $start_time
 * @property Carbon|null $stop_time
 * @property int|null $campaign_id
 * @property Carbon|null $linked_at
 * @property int|null $linked_by_id
 * @property Carbon|null $last_synced_at
 */
class MetaCampaign extends Model
{
    /** @use HasFactory<MetaCampaignFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * The link columns are absent on purpose: `LinkMetaCampaignAction` owns
     * them, the way `ChangeLeadStatusAction` owns a lead's status. A sync that
     * could write `campaign_id` would be a sync that could silently re-aim
     * somebody's reporting.
     *
     * @var list<string>
     */
    protected $fillable = [
        'meta_campaign_id',
        'ad_account_id',
        'name',
        'objective',
        'status',
        'effective_status',
        'daily_budget',
        'lifetime_budget',
        'start_time',
        'stop_time',
        'last_synced_at',
    ];

    /**
     * `status()` and `objective()` are named after their columns, so the keys
     * have to be present on every instance — see
     * .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => null,
        'objective' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Money is decimal, never float: a budget out by a hundredth is one
            // somebody has to explain.
            'daily_budget' => 'decimal:2',
            'lifetime_budget' => 'decimal:2',
            'start_time' => 'datetime',
            'stop_time' => 'datetime',
            'linked_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * What the audit trail records. The link, deliberately, and not the figures:
     * "who pointed this at that campaign" is a decision somebody made, and a
     * sync rewriting a budget every quarter of an hour is not.
     *
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return ['meta_campaign_id', 'name', 'campaign_id'];
    }

    public static function activitySubjectLabel(): string
    {
        return 'Meta campaign';
    }

    /**
     * The CRM campaign this is linked to.
     *
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_id');
    }

    /**
     * The ad sets under this campaign.
     *
     * Joined on **Meta's** id rather than a key of ours: Meta paginates, an ad
     * set routinely arrives before its campaign, and a sync that resolved an
     * integer parent would have to write in two passes.
     *
     * @return HasMany<MetaAdSet, $this>
     */
    public function adSets(): HasMany
    {
        return $this->hasMany(MetaAdSet::class, 'meta_campaign_id', 'meta_campaign_id');
    }

    /**
     * @return HasMany<MetaInsight, $this>
     */
    public function insights(): HasMany
    {
        return $this->hasMany(MetaInsight::class, 'entity_id', 'meta_campaign_id')
            ->where('level', MetaAdLevel::Campaign->value);
    }

    public function status(): ?string
    {
        $status = $this->getAttributeValue('status');

        return is_string($status) && $status !== '' ? $status : null;
    }

    public function objective(): ?string
    {
        $objective = $this->getAttributeValue('objective');

        return is_string($objective) && $objective !== '' ? $objective : null;
    }

    public function isLinked(): bool
    {
        return $this->campaign_id !== null;
    }

    /**
     * Whether Meta says this is actually running.
     *
     * `effective_status` rather than `status`: a campaign set ACTIVE inside a
     * disabled ad account spends nothing, and only the second column says so.
     */
    public function isRunning(): bool
    {
        return ($this->effective_status ?? $this->status()) === 'ACTIVE';
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLinked(Builder $query): Builder
    {
        return $query->whereNotNull($query->qualifyColumn('campaign_id'));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForAdAccount(Builder $query, string $adAccountId): Builder
    {
        return $query->where($query->qualifyColumn('ad_account_id'), $adAccountId);
    }
}
