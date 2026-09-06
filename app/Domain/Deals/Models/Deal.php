<?php

namespace App\Domain\Deals\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Concerns\ScopesByAccessLevel;
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
 * @property string|null $value
 * @property Carbon|null $expected_close_date
 * @property string $stage
 * @property string|null $description
 * @property int $owner_id
 */
class Deal extends Model
{
    /** @use HasFactory<DealFactory> */
    use HasFactory;

    use RecordsActivity;
    use ScopesByAccessLevel;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'account_id',
        'contact_id',
        'lead_id',
        'value',
        'expected_close_date',
        'stage',
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
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'expected_close_date' => 'date',
        ];
    }

    /**
     * An explicit allowlist, never logAll().
     *
     * @return array<int, string>
     */
    protected function activityAttributes(): array
    {
        return ['name', 'account_id', 'contact_id', 'value', 'expected_close_date', 'stage', 'owner_id'];
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
     * The value discounted by how likely the stage is to close.
     */
    public function weightedValue(): float
    {
        return round((float) $this->value * $this->stage()->probability() / 100, 2);
    }

    public function isOpen(): bool
    {
        return $this->stage()->isOpen();
    }

    // -- Queries -------------------------------------------------------------

    /**
     * @param  Builder<Deal>  $query
     * @return Builder<Deal>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn($query->qualifyColumn('stage'), [
            DealStage::Won->value,
            DealStage::Lost->value,
        ]);
    }
}
