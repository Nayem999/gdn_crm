<?php

namespace Database\Factories;

use App\Domain\CustomFields\Models\CustomField;
use App\Domain\CustomFields\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<CustomFieldValue>
 */
class CustomFieldValueFactory extends Factory
{
    protected $model = CustomFieldValue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'custom_field_id' => CustomField::factory(),
            'value_string' => fake()->word(),
        ];
    }

    /**
     * The answer written into whichever column the field's type uses, with
     * every other value column left null — the same invariant
     * HasCustomFields::writeCustomField keeps.
     */
    public function answering(CustomField $field, mixed $value): static
    {
        return $this->state(fn () => [
            'custom_field_id' => $field->id,
            ...CustomFieldValue::attributesFor($field->type(), $field->type()->cast($value, $field->optionKeys())),
        ]);
    }

    public function on(Model $record): static
    {
        return $this->state(fn () => [
            'customizable_type' => $record->getMorphClass(),
            'customizable_id' => $record->getKey(),
        ]);
    }
}
