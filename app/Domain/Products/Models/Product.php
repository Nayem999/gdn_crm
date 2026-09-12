<?php

namespace App\Domain\Products\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\CustomFields\Concerns\HasCustomFields;
use App\Domain\Products\Enums\ProductKind;
use App\Domain\Products\Enums\ProductUnit;
use App\Domain\Products\ProductFields;
use App\Domain\Shared\Concerns\ScopesByAccessLevel;
use App\Models\User;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One thing the company sells.
 *
 * A bundle is one of these too, with `kind` saying so and `components` holding
 * what is in it — see the migration for why that is not a second table.
 *
 * `list_price` is the catalogue price and the last word in price resolution. It
 * is not "the price": what a document charges comes from `PriceResolver`, which
 * consults the price books first.
 *
 * @property int $id
 * @property string $name
 * @property string|null $sku
 * @property string $kind
 * @property string $unit
 * @property string|null $description
 * @property string|null $category
 * @property string|null $cost_price
 * @property string $list_price
 * @property string|null $tax_rate
 * @property bool $is_active
 * @property int $owner_id
 */
class Product extends Model
{
    use HasCustomFields;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use RecordsActivity;
    use ScopesByAccessLevel;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'sku',
        'kind',
        'unit',
        'description',
        'category',
        'cost_price',
        'list_price',
        'tax_rate',
        'is_active',
        'owner_id',
    ];

    /**
     * `kind()` and `unit()` are named after their columns, so both need a
     * default or a partially-created instance reads the method and Laravel
     * takes it for a relation. See .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => ProductKind::Product->value,
        'unit' => ProductUnit::Each->value,
        'list_price' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            // Kept as decimal strings rather than cast to float: a float cannot
            // hold 0.1, and money that is out by a hundredth is money somebody
            // has to explain. Callers that need arithmetic cast at the point of
            // use, where the rounding is visible.
            'cost_price' => 'decimal:2',
            'list_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
        ];
    }

    /**
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return ['name', 'sku', 'kind', 'unit', 'category', 'cost_price', 'list_price', 'tax_rate', 'is_active', 'owner_id'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<PriceBookEntry, $this>
     */
    public function priceBookEntries(): HasMany
    {
        return $this->hasMany(PriceBookEntry::class);
    }

    /**
     * What is in this bundle, in order.
     *
     * @return HasMany<ProductBundleItem, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(ProductBundleItem::class, 'bundle_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * The bundles this product is part of — what a delete has to answer to.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function partOfBundles(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'product_bundle_items', 'product_id', 'bundle_id')
            ->withPivot('quantity');
    }

    public function kind(): ProductKind
    {
        return ProductKind::tryFrom((string) $this->getAttributeValue('kind')) ?? ProductKind::Product;
    }

    public function unit(): ProductUnit
    {
        return ProductUnit::tryFrom((string) $this->getAttributeValue('unit')) ?? ProductUnit::Each;
    }

    public function isBundle(): bool
    {
        return $this->kind()->hasComponents();
    }

    /**
     * What this product is worth at catalogue price.
     *
     * A bundle's is its own list price: a bundle exists precisely because it is
     * priced differently from the sum of its parts. `componentTotal()` is the
     * sum, offered beside it so a screen can show the saving.
     */
    public function listPrice(): float
    {
        return (float) $this->getAttributeValue('list_price');
    }

    /**
     * What the things inside this bundle come to at their own list prices.
     *
     * Zero for anything that is not a bundle, and for a bundle whose components
     * have not been loaded — the caller eager-loads `components.product`, and a
     * lazy load inside a list of fifty rows is fifty queries.
     */
    public function componentTotal(): float
    {
        if (! $this->isBundle()) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($this->components as $item) {
            $total += (float) $item->quantity * ($item->product?->listPrice() ?? 0.0);
        }

        return round($total, 2);
    }

    /**
     * The margin on a unit, or null when there is no cost to compare against.
     */
    public function margin(): ?float
    {
        $cost = $this->getAttributeValue('cost_price');

        return $cost === null ? null : round($this->listPrice() - (float) $cost, 2);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeOfKind(Builder $query, ProductKind $kind): Builder
    {
        return $query->where($query->qualifyColumn('kind'), $kind->value);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($term) {
            foreach (ProductFields::searchColumns() as $column) {
                $inner->orWhere($inner->qualifyColumn($column), 'like', '%'.$term.'%');
            }
        });
    }

    /**
     * The distinct categories in use, for a filter that offers what exists
     * rather than a list somebody has to keep in step.
     *
     * @return Collection<int, Product>
     */
    public static function categories(): Collection
    {
        /** @var Collection<int, Product> $categories */
        $categories = self::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        return $categories;
    }
}
