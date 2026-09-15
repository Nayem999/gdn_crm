<?php

namespace App\Domain\Meta\Models;

use App\Domain\Meta\Enums\MetaAdLevel;
use Database\Factories\MetaAdSetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One audience and budget under a campaign.
 *
 * The level most of the useful comparisons happen at: two ad sets under one
 * campaign are the same offer shown to different people, so their costs per lead
 * are the figure that says which audience is worth more money.
 *
 * `optimisation_goal` is stored because it is what makes that comparison fair or
 * not — an ad set optimised for link clicks and one optimised for leads are not
 * competing at the same thing, and a table that did not say so would invite the
 * conclusion that one of them is simply better.
 *
 * @property int $id
 * @property string $meta_ad_set_id
 * @property string $meta_campaign_id
 * @property string $name
 * @property string|null $status
 * @property string|null $effective_status
 * @property string|null $optimisation_goal
 * @property string|null $billing_event
 * @property string|null $daily_budget
 * @property string|null $lifetime_budget
 * @property Carbon|null $start_time
 * @property Carbon|null $end_time
 * @property Carbon|null $last_synced_at
 */
class MetaAdSet extends Model
{
    /** @use HasFactory<MetaAdSetFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'meta_ad_set_id',
        'meta_campaign_id',
        'name',
        'status',
        'effective_status',
        'optimisation_goal',
        'billing_event',
        'daily_budget',
        'lifetime_budget',
        'start_time',
        'end_time',
        'last_synced_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'daily_budget' => 'decimal:2',
            'lifetime_budget' => 'decimal:2',
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MetaCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MetaCampaign::class, 'meta_campaign_id', 'meta_campaign_id');
    }

    /**
     * @return HasMany<MetaAd, $this>
     */
    public function ads(): HasMany
    {
        return $this->hasMany(MetaAd::class, 'meta_ad_set_id', 'meta_ad_set_id');
    }

    /**
     * @return HasMany<MetaInsight, $this>
     */
    public function insights(): HasMany
    {
        return $this->hasMany(MetaInsight::class, 'entity_id', 'meta_ad_set_id')
            ->where('level', MetaAdLevel::AdSet->value);
    }

    public function status(): ?string
    {
        $status = $this->getAttributeValue('status');

        return is_string($status) && $status !== '' ? $status : null;
    }
}
