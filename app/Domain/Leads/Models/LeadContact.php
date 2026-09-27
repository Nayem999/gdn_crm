<?php

namespace App\Domain\Leads\Models;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\LeadContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * One person on file linked to one lead. A lead can have any number.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $lead_id
 * @property int $contact_id
 * @property int $position
 */
class LeadContact extends Pivot
{
    use BelongsToTenant;

    /** @use HasFactory<LeadContactFactory> */
    use HasFactory;

    /**
     * A real, named table with its own id — Pivot would guess the singular.
     */
    protected $table = 'lead_contacts';

    public $incrementing = true;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'lead_id',
        'contact_id',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
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
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
