<?php

namespace App\Domain\CustomFields\Concerns;

use App\Domain\CustomFields\CustomFieldRegistry;
use App\Domain\CustomFields\Models\CustomField;
use App\Domain\CustomFields\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RuntimeException;

/**
 * Custom field answers for a record.
 *
 * Reading is by **key**, never by id: a key is permanent and an id is an
 * implementation detail, so `$lead->customField('industry_sector')` keeps
 * working when a field is renamed.
 *
 * Writing goes through `saveCustomFields()`, which upserts one row per field and
 * clears every other value column — a row that kept a stale `value_string`
 * after its field changed type would still match a text filter, and the record
 * would turn up in a list that does not describe it.
 *
 * Validation is not here. `CustomFieldValidator` owns it, so a form and a
 * queued import are checked by the same code rather than by two copies of it.
 */
trait HasCustomFields
{
    /**
     * @return MorphMany<CustomFieldValue, $this>
     */
    public function customFieldValues(): MorphMany
    {
        return $this->morphMany(CustomFieldValue::class, 'customizable');
    }

    /**
     * What the data-view kit should eager load to answer this model's custom
     * field cells.
     *
     * Declared by the trait that owns the relation rather than named in the
     * kit: a list screen over a model **without** custom fields — Phase 8's
     * delivery log is the first — must not be asked for a relation it has not
     * got, and a literal name in the kit is one that gets checked against every
     * model the kit is ever used with.
     *
     * @return array<int, string>
     */
    public function dataViewCustomFieldEagerLoads(): array
    {
        return ['customFieldValues'];
    }

    /**
     * This model's module key, as the registry names it.
     */
    public function customFieldModule(): string
    {
        $module = CustomFieldRegistry::keyFor($this);

        if ($module === null) {
            // A model using this trait but missing from the registry would
            // silently collect no fields, which looks like "none configured".
            // CustomFieldRegistryTest catches it, and so does this.
            throw new RuntimeException(static::class.' uses HasCustomFields but is not in CustomFieldRegistry.');
        }

        return $module;
    }

    /**
     * The active fields defined for this model's module, in order.
     *
     * @return Collection<int, CustomField>
     */
    public function customFields(): Collection
    {
        return CustomField::query()
            ->forModule($this->customFieldModule())
            ->active()
            ->ordered()
            ->get();
    }

    /**
     * Every answer this record holds, keyed by field key.
     *
     * Inactive fields are included: switching a field off hides it from the
     * form, it does not throw away what people already answered, and a
     * timeline or an export that omitted it would be describing the record
     * incompletely.
     *
     * @return array<string, string|float|bool|array<int, string>|int|null>
     */
    public function customFieldValuesByKey(): array
    {
        $values = [];

        foreach ($this->customFieldValues()->with('field')->get() as $row) {
            $field = $row->field;

            if ($field === null) {
                continue;
            }

            $values[$field->key] = $row->value($field->type());
        }

        return $values;
    }

    /**
     * One answer, by field key. Null when the field has no answer, and also
     * when this module has no such field — the caller asked a question this
     * record cannot answer either way.
     *
     * @return string|float|bool|array<int, string>|int|null
     */
    public function customField(string $key): string|float|bool|array|int|null
    {
        $field = $this->customFieldDefinition($key);

        if ($field === null) {
            return null;
        }

        $row = $this->customFieldValues()->where('custom_field_id', $field->id)->first();

        return $row?->value($field->type());
    }

    /**
     * A field definition on this module, by key, active or not.
     */
    public function customFieldDefinition(string $key): ?CustomField
    {
        return CustomField::query()
            ->forModule($this->customFieldModule())
            ->where('key', $key)
            ->first();
    }

    /**
     * Write answers, keyed by field key.
     *
     * Only keys that name a field **on this module** are written; anything else
     * is ignored rather than stored, so a payload cannot invent a field. Keys
     * the caller left out are untouched — this is a partial update, which is
     * what an edit form that only shows some fields needs. Pass an explicit
     * null to clear one.
     *
     * @param  array<string, mixed>  $values
     * @return int how many answers were written or cleared
     */
    public function saveCustomFields(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        $fields = $this->customFields()->keyBy('key');
        $written = 0;

        foreach ($values as $key => $value) {
            $field = $fields->get((string) $key);

            if (! $field instanceof CustomField) {
                continue;
            }

            $this->writeCustomField($field, $value);
            $written++;
        }

        $this->unsetRelation('customFieldValues');

        return $written;
    }

    /**
     * Store one answer, or remove the row when the answer is nothing.
     *
     * A cleared field deletes its row rather than storing a row of nulls: an
     * absent answer and an answer of "nothing" are the same thing, and keeping
     * empty rows would make every "is not empty" filter wrong.
     */
    protected function writeCustomField(CustomField $field, mixed $value): void
    {
        $type = $field->type();
        $cast = $type->cast($value, $field->optionKeys());

        if ($cast === null) {
            $this->customFieldValues()->where('custom_field_id', $field->id)->delete();

            return;
        }

        $this->customFieldValues()->updateOrCreate(
            ['custom_field_id' => $field->id],
            CustomFieldValue::attributesFor($type, $cast),
        );
    }
}
