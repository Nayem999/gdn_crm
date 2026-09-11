<?php

namespace App\Domain\CustomFields\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\CustomFields\CustomFieldRegistry;
use App\Domain\CustomFields\Enums\CustomFieldType;
use Database\Factories\CustomFieldFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One field an administrator has added to a module.
 *
 * @property int $id
 * @property string $module
 * @property string $key
 * @property string $label
 * @property string $type
 * @property string|null $help
 * @property bool $is_required
 * @property bool $is_active
 * @property int $position
 * @property array<int, mixed>|null $options Whatever the JSON column holds. Not
 *                                           typed as a list of {key, label} pairs on purpose: it is decoded
 *                                           from JSON, so a hand-edited row can hold anything, and optionMap()
 *                                           is what turns it back into a shape the rest of the code can trust.
 * @property string|null $lookup_module
 * @property string|null $default_value
 */
class CustomField extends Model
{
    /** @use HasFactory<CustomFieldFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'module',
        'key',
        'label',
        'type',
        'help',
        'is_required',
        'is_active',
        'position',
        'options',
        'lookup_module',
        'default_value',
    ];

    /**
     * Defaults for the columns that share a name with a method on this model.
     *
     * `type()` reads the `type` column, so an instance created without it would
     * call the method looking for a relation and fail — see
     * .ai/rules/models-name-collisions.md.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'text',
        'module' => 'leads',
        'is_required' => false,
        'is_active' => true,
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
            'options' => 'array',
        ];
    }

    /**
     * @return list<string>
     */
    protected function activityAttributes(): array
    {
        return ['module', 'key', 'label', 'type', 'is_required', 'is_active', 'position', 'lookup_module'];
    }

    public static function activitySubjectLabel(): string
    {
        return 'Custom field';
    }

    // -- Relations ------------------------------------------------------------

    /**
     * @return HasMany<CustomFieldValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    // -- Typed accessors ------------------------------------------------------

    /**
     * Read through getAttributeValue so the accessor cannot re-enter itself on
     * an instance where the attribute is missing.
     */
    public function type(): CustomFieldType
    {
        return CustomFieldType::tryFrom((string) $this->getAttributeValue('type')) ?? CustomFieldType::Text;
    }

    public function moduleLabel(): string
    {
        return CustomFieldRegistry::label((string) $this->getAttributeValue('module'));
    }

    /**
     * The choices this field offers, as key => label.
     *
     * Stored as a list of `{key, label}` pairs rather than a map, because JSON
     * object key order is not guaranteed and the order an administrator put the
     * options in is the order the dropdown should show them in.
     *
     * @return array<string, string>
     */
    public function optionMap(): array
    {
        $map = [];

        foreach ($this->options ?? [] as $option) {
            if (! is_array($option) || ! isset($option['key'])) {
                continue;
            }

            $key = (string) $option['key'];
            $map[$key] = (string) ($option['label'] ?? $key);
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    public function optionKeys(): array
    {
        return array_keys($this->optionMap());
    }

    public function optionLabel(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        return $this->optionMap()[$key] ?? $key;
    }

    /**
     * The validation rules a submitted value for this field must pass.
     *
     * @return array<int, string>
     */
    public function rules(): array
    {
        return $this->type()->rules((bool) $this->is_required, $this->optionKeys());
    }

    /**
     * @return array<int, string>
     */
    public function itemRules(): array
    {
        return $this->type()->itemRules($this->optionKeys());
    }

    /**
     * The column in `custom_field_values` this field's answers live in.
     */
    public function column(): string
    {
        return $this->type()->column();
    }

    // -- Keys -----------------------------------------------------------------

    /**
     * Turn a label into a key that will not collide inside its module.
     *
     * Keys are **permanent and derived, never taken from the browser**: an
     * import mapping, a saved filter and an export column all refer to a field
     * by its key, and reassigning one would silently repoint every one of them
     * at a different field. Same rule as a pipeline stage's key.
     *
     * @param  array<int, string>  $taken
     */
    public static function keyFrom(string $label, array $taken = []): string
    {
        $base = str($label)->slug('_')->limit(48, '')->toString();

        if ($base === '') {
            $base = 'field';
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
     * An option's key, derived the same way and for the same reason: a stored
     * value refers to it, so renaming the label must not move the answers.
     *
     * Slugged, which is also what keeps the comma-separated `in` validation
     * rule safe.
     *
     * @param  array<int, string>  $taken
     */
    public static function optionKey(string $label, array $taken = []): string
    {
        $base = str($label)->slug('_')->limit(48, '')->toString();

        if ($base === '') {
            $base = 'option';
        }

        $key = $base;
        $suffix = 2;

        while (in_array($key, $taken, true)) {
            $key = $base.'_'.$suffix;
            $suffix++;
        }

        return $key;
    }

    // -- Queries --------------------------------------------------------------

    /**
     * @param  Builder<CustomField>  $query
     * @return Builder<CustomField>
     */
    public function scopeForModule(Builder $query, string $module): Builder
    {
        return $query->where($query->qualifyColumn('module'), $module);
    }

    /**
     * @param  Builder<CustomField>  $query
     * @return Builder<CustomField>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * @param  Builder<CustomField>  $query
     * @return Builder<CustomField>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy($query->qualifyColumn('position'))
            ->orderBy($query->qualifyColumn('id'));
    }
}
