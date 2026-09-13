<?php

namespace App\Domain\Knowledge\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A section of the knowledge base.
 *
 * One level of nesting, and no more: a section and its subsections is as deep
 * as a library can go before nobody finds anything, which is the opposite of
 * the point.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $position
 * @property-read Collection<int, Category> $children
 * @property-read Collection<int, Article> $articles
 */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use RecordsActivity;
    use SoftDeletes;

    protected $table = 'kb_categories';

    /**
     * @var list<string>
     */
    protected $fillable = ['parent_id', 'name', 'slug', 'description', 'position'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['position' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * @return array<int, string>
     */
    protected function activityAttributes(): array
    {
        return ['name', 'slug', 'parent_id', 'position'];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('position')->orderBy('name');
    }

    /**
     * @return HasMany<Article, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'kb_category_id');
    }

    public function displayName(): string
    {
        return $this->name;
    }

    /**
     * Its name, prefixed by its parent's when it has one — a subsection called
     * "Setup" means nothing in a flat list.
     */
    public function path(): string
    {
        $parent = $this->parent;

        return $parent === null ? $this->name : $parent->name.' / '.$this->name;
    }

    public function isTopLevel(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('parent_id'));
    }

    /**
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($query->qualifyColumn('position'))->orderBy($query->qualifyColumn('name'));
    }

    public static function slugFor(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'section';
        }

        $slug = $base;
        $suffix = 1;

        while (self::query()
            ->withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
