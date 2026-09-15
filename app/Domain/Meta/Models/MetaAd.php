<?php

namespace App\Domain\Meta\Models;

use App\Domain\Meta\Enums\MetaAdLevel;
use Database\Factories\MetaAdFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The advertisement somebody actually saw.
 *
 * The bottom of the chain a lead is attributed through, and the level at which
 * "which of these two pictures produced customers" is answerable.
 *
 * Only enough of the creative to recognise it: a title and a line of body text.
 * Storing the assets would make this a media library nobody asked for, and
 * Meta's own URLs for them expire — a column full of dead links is worse than no
 * column, because somebody has to work out why the images stopped loading.
 *
 * @property int $id
 * @property string $meta_ad_id
 * @property string $meta_ad_set_id
 * @property string|null $meta_campaign_id
 * @property string $name
 * @property string|null $status
 * @property string|null $effective_status
 * @property string|null $creative_name
 * @property string|null $creative_summary
 * @property Carbon|null $last_synced_at
 */
class MetaAd extends Model
{
    /** @use HasFactory<MetaAdFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'meta_ad_id',
        'meta_ad_set_id',
        'meta_campaign_id',
        'name',
        'status',
        'effective_status',
        'creative_name',
        'creative_summary',
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
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MetaAdSet, $this>
     */
    public function adSet(): BelongsTo
    {
        return $this->belongsTo(MetaAdSet::class, 'meta_ad_set_id', 'meta_ad_set_id');
    }

    /**
     * @return BelongsTo<MetaCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MetaCampaign::class, 'meta_campaign_id', 'meta_campaign_id');
    }

    /**
     * @return HasMany<MetaInsight, $this>
     */
    public function insights(): HasMany
    {
        return $this->hasMany(MetaInsight::class, 'entity_id', 'meta_ad_id')
            ->where('level', MetaAdLevel::Ad->value);
    }

    public function status(): ?string
    {
        $status = $this->getAttributeValue('status');

        return is_string($status) && $status !== '' ? $status : null;
    }
}
