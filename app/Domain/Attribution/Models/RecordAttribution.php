<?php

namespace App\Domain\Attribution\Models;

use App\Domain\Attribution\MarketingAttribution;
use Database\Factories\RecordAttributionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * The stored form of one record's attribution.
 *
 * Deliberately thin: everything that reasons about attribution does so through
 * the `MarketingAttribution` value object, which can exist before a record does
 * — a webhook has attribution in hand before it has decided whether to create a
 * lead, and a value object is what can be carried through that decision.
 *
 * No `ScopesByAccessLevel`: this is never queried on its own. It is read
 * through the record it belongs to, which is already scoped, and a list of
 * attribution rows would be a list of other people's leads by another name.
 *
 * @property int $id
 * @property string $attributable_type
 * @property int $attributable_id
 * @property string|null $source
 * @property string|null $meta_lead_id
 * @property Carbon $captured_at
 */
class RecordAttribution extends Model
{
    /** @use HasFactory<RecordAttributionFactory> */
    use HasFactory;

    protected $table = 'marketing_attributions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'source',
        'source_detail',
        'meta_lead_id',
        'page_id',
        'form_id',
        'form_name',
        'meta_campaign_id',
        'meta_campaign_name',
        'meta_ad_set_id',
        'meta_ad_set_name',
        'meta_ad_id',
        'meta_ad_name',
        'click_id',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'captured_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attributable(): MorphTo
    {
        return $this->morphTo();
    }

    public function toValue(): MarketingAttribution
    {
        return MarketingAttribution::fromArray($this->attributesToArray());
    }
}
