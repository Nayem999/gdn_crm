<?php

namespace App\Domain\Meta\Models;

use App\Domain\Leads\Models\Lead;
use App\Domain\Meta\Enums\MetaLeadStatus;
use Database\Factories\MetaLeadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One lead-ad submission, and what this application made of it.
 *
 * The row exists before the lead does and survives whatever happens to it. That
 * is the point: "somebody filled the form in at 14:02 and there is no lead" is
 * a question a CRM has to be able to answer, and it cannot be answered from a
 * table of the leads that were created.
 *
 * **No `ScopesByAccessLevel`.** This is not a customer record — it is the
 * account of a delivery, read by whoever administers the integration, and the
 * lead it points at is scoped on its own. A list of submissions scoped by owner
 * would also be a list with holes in exactly the place somebody is
 * investigating.
 *
 * `status`, `lead_id`, `error` and `processed_at` are out of `$fillable`: the
 * pipeline owns them, the way it owns an integration event's. Nothing that
 * records an arrival may declare what became of it.
 *
 * @property int $id
 * @property string $meta_lead_id
 * @property int|null $meta_form_id
 * @property string|null $form_id
 * @property string|null $page_id
 * @property string|null $meta_campaign_id
 * @property string|null $meta_campaign_name
 * @property string|null $meta_ad_set_id
 * @property string|null $meta_ad_set_name
 * @property string|null $meta_ad_id
 * @property string|null $meta_ad_name
 * @property string|null $payload
 * @property int|null $lead_id
 * @property string $status
 * @property string|null $error
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 */
class MetaLead extends Model
{
    /** @use HasFactory<MetaLeadFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'meta_lead_id',
        'meta_form_id',
        'form_id',
        'page_id',
        'meta_campaign_id',
        'meta_campaign_name',
        'meta_ad_set_id',
        'meta_ad_set_name',
        'meta_ad_id',
        'meta_ad_name',
        'payload',
        'received_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => MetaLeadStatus::Received->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $lead) {
            // When Meta says the submission happened, or failing that when we
            // heard about it. A row without one would order the list by nothing
            // in particular.
            if (! $lead->getAttributeValue('received_at')) {
                $lead->received_at = now();
            }
        });
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<MetaForm, $this>
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(MetaForm::class, 'meta_form_id');
    }

    /**
     * getAttributeValue, not $this->status — the method and the column share a
     * name. See .ai/rules/models-name-collisions.md.
     */
    public function status(): MetaLeadStatus
    {
        return MetaLeadStatus::tryFrom((string) $this->getAttributeValue('status'))
            ?? MetaLeadStatus::Received;
    }

    public function isSettled(): bool
    {
        return $this->status()->isSettled();
    }

    /**
     * @param  Builder<MetaLead>  $query
     * @return Builder<MetaLead>
     */
    public function scopeWithStatus(Builder $query, MetaLeadStatus $status): Builder
    {
        return $query->where($query->qualifyColumn('status'), $status->value);
    }
}
