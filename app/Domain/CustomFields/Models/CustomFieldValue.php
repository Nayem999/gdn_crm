<?php

namespace App\Domain\CustomFields\Models;

use App\Domain\CustomFields\Enums\CustomFieldType;
use Database\Factories\CustomFieldValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * What one record answered for one custom field.
 *
 * Exactly one of the `value_*` columns is filled, chosen by the field's type.
 * Nothing outside this class should read those columns directly: `value()` and
 * `fill()` are the door, so a type's storage column can change without every
 * caller having to know.
 *
 * @property int $id
 * @property int $custom_field_id
 * @property string $customizable_type
 * @property int $customizable_id
 * @property string|null $value_string
 * @property string|null $value_number
 * @property Carbon|null $value_date
 * @property bool|null $value_boolean
 * @property array<int, string>|null $value_json
 * @property int|null $value_lookup_id
 */
class CustomFieldValue extends Model
{
    /** @use HasFactory<CustomFieldValueFactory> */
    use HasFactory;

    /**
     * Every value column, so a write can clear the others in one go.
     *
     * A row that kept a stale `value_string` after its field was changed to a
     * number would still match a text filter, which is how a record turns up in
     * a list that does not describe it.
     *
     * @var list<string>
     */
    public const VALUE_COLUMNS = [
        'value_string',
        'value_number',
        'value_date',
        'value_boolean',
        'value_json',
        'value_lookup_id',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'custom_field_id',
        'customizable_type',
        'customizable_id',
        ...self::VALUE_COLUMNS,
    ];

    protected function casts(): array
    {
        return [
            'value_date' => 'date',
            'value_boolean' => 'boolean',
            'value_json' => 'array',
            'value_lookup_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CustomField, $this>
     */
    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function customizable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The answer, read out of whichever column this type uses.
     *
     * `value_number` is a DECIMAL, which comes back from the driver as a
     * string; it is cast here rather than in `casts()` so money keeps its
     * exactness in the column and becomes a float only at the point of use.
     *
     * @return string|float|bool|array<int, string>|int|null
     */
    public function value(CustomFieldType $type): string|float|bool|array|int|null
    {
        $raw = $this->getAttribute($type->column());

        if ($raw === null) {
            return null;
        }

        return match ($type) {
            CustomFieldType::Number, CustomFieldType::Currency => (float) $raw,
            CustomFieldType::Checkbox => (bool) $raw,
            CustomFieldType::Lookup => (int) $raw,
            CustomFieldType::MultiSelect => is_array($raw) ? $raw : [],
            CustomFieldType::Date => $raw instanceof Carbon ? $raw->format('Y-m-d') : (string) $raw,
            default => (string) $raw,
        };
    }

    /**
     * The attributes that store one answer: the type's own column set, and
     * every other value column cleared.
     *
     * @return array<string, mixed>
     */
    public static function attributesFor(CustomFieldType $type, mixed $value): array
    {
        $attributes = array_fill_keys(self::VALUE_COLUMNS, null);
        $attributes[$type->column()] = $value;

        return $attributes;
    }
}
