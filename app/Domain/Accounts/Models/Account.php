<?php

namespace App\Domain\Accounts\Models;

use App\Domain\Accounts\Enums\AccountSize;
use App\Domain\Accounts\Enums\Industry;
use App\Domain\Activities\Models\Activity;
use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Shared\Concerns\MergesWithDuplicates;
use App\Domain\Shared\Concerns\ScopesByAccessLevel;
use App\Domain\Timeline\Concerns\HasTimeline;
use App\Models\User;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A customer organisation.
 *
 * Not to be confused with App\Domain\Company\Models\Company, which is the single
 * organisation this CRM is installed for. An Account is somebody the company
 * does business with.
 *
 * @property int $id
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $industry
 * @property string|null $size
 * @property string|null $annual_revenue
 * @property string|null $website
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $city
 * @property string|null $country
 * @property string|null $description
 * @property int|null $parent_id
 * @property int $owner_id
 * @property int|null $merged_into_id
 * @property Carbon|null $merged_at
 */
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
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
        'name',
        'legal_name',
        'industry',
        'size',
        'annual_revenue',
        'website',
        'email',
        'phone',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'description',
        'parent_id',
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
        'industry' => null,
        'size' => null,
    ];

    protected function casts(): array
    {
        return [
            'annual_revenue' => 'decimal:2',
            'merged_at' => 'datetime',
        ];
    }

    /**
     * What the audit trail records. An explicit allowlist, never logAll().
     *
     * @return array<int, string>
     */
    protected function activityAttributes(): array
    {
        return ['name', 'industry', 'size', 'annual_revenue', 'owner_id', 'parent_id', 'country'];
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
     * @return BelongsTo<Account, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
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

    // -- Typed accessors ----------------------------------------------------

    public function industry(): ?Industry
    {
        // getAttributeValue, not $this->industry: the method and the column
        // share a name, so on an instance that has no such attribute loaded
        // Laravel would take the property read for a relation, call this
        // method again and fail. Model::create() leaves out anything it was
        // not given, which is exactly what lead conversion does.
        $value = $this->getAttributeValue('industry');

        return $value === null ? null : Industry::tryFrom((string) $value);
    }

    public function size(): ?AccountSize
    {
        $value = $this->getAttributeValue('size');

        return $value === null ? null : AccountSize::tryFrom((string) $value);
    }

    public function displayName(): string
    {
        return $this->name;
    }

    /**
     * The website as something a browser will accept, since people type
     * "example.com" as often as they type the scheme.
     */
    public function websiteUrl(): ?string
    {
        if ($this->website === null || trim($this->website) === '') {
            return null;
        }

        return str_starts_with($this->website, 'http://') || str_starts_with($this->website, 'https://')
            ? $this->website
            : 'https://'.$this->website;
    }

    // -- Hierarchy ----------------------------------------------------------

    /**
     * Every parent up to the root, nearest first.
     *
     * Guarded against a cycle: a corrupted parent chain must not hang a page
     * render, even though CanBeParentedBy refuses to create one.
     *
     * @return Collection<int, Account>
     */
    public function ancestors(): Collection
    {
        $ancestors = collect();
        $seen = [$this->id => true];
        $current = $this->parent;

        while ($current !== null && ! isset($seen[$current->id])) {
            $seen[$current->id] = true;
            $ancestors->push($current);
            $current = $current->parent;
        }

        return $ancestors;
    }

    /**
     * Every account beneath this one, at any depth.
     *
     * @return Collection<int, Account>
     */
    public function descendants(): Collection
    {
        $descendants = collect();
        $seen = [$this->id => true];
        $queue = $this->children()->get()->all();

        while ($queue !== []) {
            /** @var Account $account */
            $account = array_shift($queue);

            if (isset($seen[$account->id])) {
                continue;
            }

            $seen[$account->id] = true;
            $descendants->push($account);

            foreach ($account->children()->get() as $child) {
                $queue[] = $child;
            }
        }

        return $descendants;
    }

    public function depth(): int
    {
        return $this->ancestors()->count();
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * Whether this account sits somewhere beneath the given one.
     */
    public function isDescendantOf(Account $account): bool
    {
        return $this->ancestors()->contains(fn (Account $ancestor) => $ancestor->id === $account->id);
    }

    /**
     * Whether the given account could become this one's parent.
     *
     * Refuses itself and any of its own descendants, which is what stops a
     * hierarchy from folding into a loop.
     */
    public function canBeParentedBy(?Account $candidate): bool
    {
        if ($candidate === null) {
            return true;
        }

        if ($candidate->id === $this->id) {
            return false;
        }

        return ! $candidate->isDescendantOf($this);
    }

    // -- Queries -------------------------------------------------------------

    /**
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($term) {
            foreach (['name', 'legal_name', 'email', 'phone', 'city'] as $column) {
                $inner->orWhere($inner->qualifyColumn($column), 'like', '%'.$term.'%');
            }
        });
    }
}
