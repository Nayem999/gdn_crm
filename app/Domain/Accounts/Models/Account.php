<?php

namespace App\Domain\Accounts\Models;

use App\Domain\Accounts\Enums\AccountSize;
use App\Domain\Accounts\Enums\Industry;
use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Shared\Concerns\ScopesByAccessLevel;
use App\Models\User;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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
 */
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

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

    protected function casts(): array
    {
        return [
            'annual_revenue' => 'decimal:2',
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

    // -- Typed accessors ----------------------------------------------------

    public function industry(): ?Industry
    {
        return $this->industry === null ? null : Industry::tryFrom($this->industry);
    }

    public function size(): ?AccountSize
    {
        return $this->size === null ? null : AccountSize::tryFrom($this->size);
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
