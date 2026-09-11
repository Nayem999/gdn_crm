<?php

namespace App\Domain\Contacts\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Activities\Models\Activity;
use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Contacts\Enums\Department;
use App\Domain\CustomFields\Concerns\HasCustomFields;
use App\Domain\Shared\Concerns\MergesWithDuplicates;
use App\Domain\Shared\Concerns\ScopesByAccessLevel;
use App\Domain\Timeline\Concerns\HasTimeline;
use App\Models\User;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A person at a customer organisation.
 *
 * An account may have many contacts, one of which is its primary. A contact may
 * have no account yet — people often arrive before anyone works out who they
 * work for.
 *
 * @property int $id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $job_title
 * @property string|null $department
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $mobile
 * @property string|null $city
 * @property string|null $country
 * @property string|null $description
 * @property int|null $account_id
 * @property bool $is_primary
 * @property int $owner_id
 * @property int|null $merged_into_id
 * @property Carbon|null $merged_at
 */
class Contact extends Model
{
    use HasCustomFields;

    /** @use HasFactory<ContactFactory> */
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
        'department',
        'email',
        'phone',
        'mobile',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'description',
        'account_id',
        'is_primary',
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
        'department' => null,
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'merged_at' => 'datetime',
        ];
    }

    /**
     * An explicit allowlist, never logAll().
     *
     * @return array<int, string>
     */
    protected function activityAttributes(): array
    {
        return ['first_name', 'last_name', 'job_title', 'department', 'email', 'account_id', 'is_primary', 'owner_id'];
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
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
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

    public function department(): ?Department
    {
        // getAttributeValue, not $this->department: the method and the column
        // share a name, so on an instance that has no such attribute loaded
        // Laravel would take the property read for a relation, call this
        // method again and fail. Model::create() leaves out anything it was
        // not given, which is exactly what lead conversion does.
        $value = $this->getAttributeValue('department');

        return $value === null ? null : Department::tryFrom((string) $value);
    }

    /**
     * Job title and organisation as one line, for a card or a list row.
     */
    public function roleLine(): ?string
    {
        $parts = array_filter([$this->job_title, $this->account?->name]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    // -- Queries -------------------------------------------------------------

    /**
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($term) {
            foreach (['first_name', 'last_name', 'email', 'phone', 'mobile', 'job_title'] as $column) {
                $inner->orWhere($inner->qualifyColumn($column), 'like', '%'.$term.'%');
            }

            // So that searching "Dana Scully" matches, not just either half.
            $inner->orWhereRaw(
                'CONCAT('.$inner->qualifyColumn('first_name').", ' ', ".$inner->qualifyColumn('last_name').') LIKE ?',
                ['%'.$term.'%']
            );
        });
    }

    /**
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopePrimary(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_primary'), true);
    }
}
