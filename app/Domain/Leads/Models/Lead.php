<?php

namespace App\Domain\Leads\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Enums\LeadGrade;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Shared\Concerns\MergesWithDuplicates;
use App\Domain\Shared\Concerns\ScopesByAccessLevel;
use App\Domain\Timeline\Concerns\HasTimeline;
use App\Models\User;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Somebody who might become a customer.
 *
 * A lead holds the organisation as a plain string rather than an account
 * reference: nobody has matched them to an account yet. Task 2.6's conversion
 * turns one into an Account, a Contact and a Deal together.
 *
 * @property int $id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $job_title
 * @property string|null $company_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $mobile
 * @property string|null $website
 * @property string|null $city
 * @property string|null $country
 * @property string $status
 * @property string|null $source
 * @property string|null $estimated_value
 * @property int $score
 * @property Carbon|null $scored_at
 * @property string|null $description
 * @property Carbon|null $status_changed_at
 * @property int $owner_id
 * @property Carbon|null $converted_at
 * @property int|null $converted_account_id
 * @property int|null $converted_contact_id
 * @property int|null $converted_deal_id
 * @property int|null $merged_into_id
 * @property Carbon|null $merged_at
 */
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    use HasTimeline;
    use MergesWithDuplicates;
    use RecordsActivity;
    use ScopesByAccessLevel;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'job_title',
        'company_name',
        'email',
        'phone',
        'mobile',
        'website',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'status',
        'source',
        'estimated_value',
        'description',
        'status_changed_at',
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
        'status' => 'new',
        'source' => null,
    ];

    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:2',
            'score' => 'integer',
            'status_changed_at' => 'datetime',
            'scored_at' => 'datetime',
            'converted_at' => 'datetime',
            'merged_at' => 'datetime',
        ];
    }

    /**
     * An explicit allowlist, never logAll(). Status is here on purpose: the
     * trail is where "who moved this and when" is answered.
     *
     * `score` is deliberately absent. It is derived from the scoring rules, not
     * decided by a person, and a rescore of the database would otherwise write
     * one entry per lead. The rules themselves are what gets audited.
     *
     * @return array<int, string>
     */
    protected function activityAttributes(): array
    {
        return [
            'first_name', 'last_name', 'company_name', 'email',
            'status', 'source', 'estimated_value', 'owner_id',
        ];
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
     * The account this lead became, if it became one.
     *
     * @return BelongsTo<Account, $this>
     */
    public function convertedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'converted_account_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function convertedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'converted_contact_id');
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function convertedDeal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'converted_deal_id');
    }

    // -- Presentation --------------------------------------------------------

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function displayName(): string
    {
        return $this->fullName();
    }

    public function initials(): string
    {
        return strtoupper(mb_substr($this->first_name, 0, 1).mb_substr($this->last_name, 0, 1));
    }

    public function status(): LeadStatus
    {
        // getAttributeValue, not $this->status: the method and the column
        // share a name, so on an instance that has no such attribute loaded
        // Laravel would take the property read for a relation, call this
        // method again and fail. Model::create() leaves out anything it was
        // not given, which is exactly what lead conversion does.
        return LeadStatus::tryFrom((string) $this->getAttributeValue('status')) ?? LeadStatus::New;
    }

    public function source(): ?LeadSource
    {
        $value = $this->getAttributeValue('source');

        return $value === null ? null : LeadSource::tryFrom((string) $value);
    }

    public function grade(): LeadGrade
    {
        return LeadGrade::forScore($this->score);
    }

    /**
     * Job title and organisation as one line, for a card or a list row.
     */
    public function roleLine(): ?string
    {
        $parts = array_filter([$this->job_title, $this->company_name]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    public function websiteUrl(): ?string
    {
        if ($this->website === null || trim($this->website) === '') {
            return null;
        }

        return str_starts_with($this->website, 'http://') || str_starts_with($this->website, 'https://')
            ? $this->website
            : 'https://'.$this->website;
    }

    /**
     * How long the lead has sat where it is, for spotting stalled work.
     */
    public function daysInStatus(): int
    {
        $since = $this->status_changed_at ?? $this->created_at;

        return $since === null ? 0 : (int) $since->diffInDays(now());
    }

    // -- Status --------------------------------------------------------------

    public function canTransitionTo(LeadStatus $target): bool
    {
        return $this->status()->canTransitionTo($target);
    }

    /**
     * @return array<int, LeadStatus>
     */
    public function allowedTransitions(): array
    {
        return $this->status()->allowedTransitions();
    }

    public function isConverted(): bool
    {
        return $this->status() === LeadStatus::Converted;
    }

    // -- Queries -------------------------------------------------------------

    /**
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($term) {
            foreach (['first_name', 'last_name', 'company_name', 'email', 'phone', 'mobile'] as $column) {
                $inner->orWhere($inner->qualifyColumn($column), 'like', '%'.$term.'%');
            }

            // So that searching a whole name matches, not just either half.
            $inner->orWhereRaw(
                'CONCAT('.$inner->qualifyColumn('first_name').", ' ', ".$inner->qualifyColumn('last_name').') LIKE ?',
                ['%'.$term.'%']
            );
        });
    }

    /**
     * Leads still worth working, i.e. not converted.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn($query->qualifyColumn('status'), [
            LeadStatus::Converted->value,
            LeadStatus::Unqualified->value,
        ]);
    }
}
