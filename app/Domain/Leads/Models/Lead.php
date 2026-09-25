<?php

namespace App\Domain\Leads\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Activities\Models\Activity;
use App\Domain\Attribution\Concerns\HasMarketingAttribution;
use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Contacts\Models\Contact;
use App\Domain\CustomFields\Concerns\HasCustomFields;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Enums\LeadGrade;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Shared\Concerns\MergesWithDuplicates;
use App\Domain\Shared\Concerns\ScopesByAccessLevel;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Timeline\Concerns\HasTimeline;
use App\Models\User;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
 * @property int|null $campaign_id
 * @property int|null $lead_owner_id
 * @property Carbon|null $converted_at
 * @property int|null $converted_account_id
 * @property int|null $converted_contact_id
 * @property int|null $converted_deal_id
 * @property int|null $merged_into_id
 * @property Carbon|null $merged_at
 */
class Lead extends Model
{
    use BelongsToTenant;
    use HasCustomFields;

    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    use HasMarketingAttribution;
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
        'campaign_id',
        'lead_owner_id',
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
            'status', 'source', 'estimated_value', 'lead_owner_id',
        ];
    }

    // -- Relations ----------------------------------------------------------

    /**
     * Everybody currently responsible for this lead, in escalation order —
     * a null priority sorts after every numbered one, and ties break on
     * whoever was assigned first.
     *
     * @return HasMany<LeadAssignee, $this>
     */
    public function assignees(): HasMany
    {
        return $this->hasMany(LeadAssignee::class)
            ->orderByRaw('priority is null, priority')
            ->orderBy('assigned_at');
    }

    /**
     * The same set, as the actual User rows — for a screen that only ever
     * wants to show or search on who, not the pivot's own priority and
     * timestamps.
     *
     * @return BelongsToMany<User, $this, LeadAssignee>
     */
    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'lead_assignees')
            ->using(LeadAssignee::class)
            ->withPivot(['priority', 'assigned_at', 'escalated_at']);
    }

    /**
     * The one assignee conversion, export and a compact list row show when
     * there is only room for one name — the highest priority (lowest number),
     * falling back to whoever was assigned first when nobody set one.
     * `assignees()` is already ordered exactly this way, so this is just its
     * first row.
     *
     * Never stored, never authoritative: a lead is visible and workable
     * through the whole `assignees()` set regardless of who this returns.
     */
    public function primaryAssignee(): ?User
    {
        return $this->relationLoaded('assignees')
            ? $this->assignees->first()?->user
            : $this->assignees()->with('user')->first()?->user;
    }

    /**
     * What this record came from.
     *
     * Nullable and nulled on delete: a record whose campaign has been removed is
     * still a record, and attribution is a label on work that happened rather
     * than something the work depends on.
     *
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Who owns the lead, if anybody. A label only: visibility and the work
     * itself follow the assignees, never this.
     *
     * @return BelongsTo<User, $this>
     */
    public function leadOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_owner_id');
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

    /**
     * The tasks, calls and meetings scheduled against this record.
     *
     * Not `activities()`: that name is already taken by spatie's audit trail,
     * which RecordsActivity brings to every model here. See
     * .ai/rules/activities.md.
     *
     * @return MorphMany<Activity, $this>
     */
    public function scheduledActivities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'related')->orderBy('due_at');
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

    /**
     * Own/team/all, now read off `lead_assignees` instead of a flat owner
     * column — "own" is any lead I am on, "team" is any lead somebody on my
     * team is on. Overrides ScopesByAccessLevel's column-comparison version
     * entirely rather than pointing `accessLevelOwnerColumn()` somewhere
     * else, because there is no single column left to point it at.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match (static::resolveAccessLevelFor($user)) {
            DataAccessLevel::All => $query,
            DataAccessLevel::Own => $query->whereHas(
                'assignedUsers',
                fn (Builder $assignees) => $assignees->where('users.id', $user->id)
            ),
            DataAccessLevel::Team => $query->where(function (Builder $scoped) use ($user) {
                if ($user->current_team_id === null) {
                    $scoped->whereHas(
                        'assignedUsers',
                        fn (Builder $assignees) => $assignees->where('users.id', $user->id)
                    );

                    return;
                }

                $scoped->whereHas('assignedUsers', fn (Builder $assignees) => $assignees->whereIn(
                    'users.id',
                    User::query()->where('current_team_id', $user->current_team_id)->select('id')
                ));
            }),
        };
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
