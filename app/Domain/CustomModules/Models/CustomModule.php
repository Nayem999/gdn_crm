<?php

namespace App\Domain\CustomModules\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use Database\Factories\CustomModuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A module an administrator defined at runtime.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $plural_name
 * @property string $title_label
 * @property string $icon
 * @property string $color
 * @property string|null $description
 * @property bool $is_active
 * @property int $position
 */
class CustomModule extends Model
{
    /** @use HasFactory<CustomModuleFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * Custom module keys are prefixed everywhere they meet a built-in module's,
     * so a module called "Leads" cannot shadow the real one in the custom field
     * registry, a saved view's module column or a route.
     */
    public const PREFIX = 'cm_';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key', 'name', 'plural_name', 'title_label', 'icon', 'color',
        'description', 'is_active', 'position',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'title_label' => 'Name',
        'icon' => 'box',
        'color' => 'slate',
        'is_active' => true,
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return ['key', 'name', 'plural_name', 'is_active', 'position'];
    }

    public static function activitySubjectLabel(): string
    {
        return 'Custom module';
    }

    /**
     * @return HasMany<CustomRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(CustomRecord::class);
    }

    /**
     * The key this module is known by outside its own table — in the custom
     * field registry, in a saved view, in a URL.
     */
    public function moduleKey(): string
    {
        return self::PREFIX.$this->getAttributeValue('key');
    }

    /**
     * Turn a name into a key that will not collide.
     *
     * Permanent: a custom field row, a saved view and a URL all refer to it.
     *
     * @param  array<int, string>  $taken
     */
    public static function keyFrom(string $name, array $taken = []): string
    {
        $base = str($name)->slug('_')->limit(40, '')->toString();

        if ($base === '') {
            $base = 'module';
        }

        $key = $base;
        $suffix = 2;

        while (in_array($key, $taken, true)) {
            $key = $base.'_'.$suffix;
            $suffix++;
        }

        return $key;
    }

    /**
     * @param  Builder<CustomModule>  $query
     * @return Builder<CustomModule>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * @param  Builder<CustomModule>  $query
     * @return Builder<CustomModule>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($query->qualifyColumn('position'))
            ->orderBy($query->qualifyColumn('id'));
    }
}
