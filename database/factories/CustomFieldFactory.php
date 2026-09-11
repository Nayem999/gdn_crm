<?php

namespace Database\Factories;

use App\Domain\CustomFields\Enums\CustomFieldType;
use App\Domain\CustomFields\Models\CustomField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomField>
 */
class CustomFieldFactory extends Factory
{
    protected $model = CustomField::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = fake()->unique()->words(2, true);

        return [
            'module' => 'leads',
            'key' => CustomField::keyFrom($label),
            'label' => ucfirst($label),
            'type' => CustomFieldType::Text->value,
            'help' => null,
            'is_required' => false,
            'is_active' => true,
            'position' => 0,
            'options' => null,
            'lookup_module' => null,
            'default_value' => null,
        ];
    }

    public function forModule(string $module): static
    {
        return $this->state(fn () => ['module' => $module]);
    }

    /**
     * A field of a given type, with the extras that type needs so the fixture
     * describes something the application could actually have produced.
     *
     * @param  array<int, string>  $optionLabels
     */
    public function ofType(CustomFieldType $type, array $optionLabels = ['One', 'Two', 'Three']): static
    {
        return $this->state(function () use ($type, $optionLabels) {
            $options = null;

            if ($type->hasOptions()) {
                $options = [];
                $taken = [];

                foreach ($optionLabels as $label) {
                    $key = CustomField::optionKey($label, $taken);
                    $taken[] = $key;
                    $options[] = ['key' => $key, 'label' => $label];
                }
            }

            return [
                'type' => $type->value,
                'options' => $options,
                'lookup_module' => $type->isLookup() ? 'accounts' : null,
            ];
        });
    }

    public function required(): static
    {
        return $this->state(fn () => ['is_required' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function labelled(string $label): static
    {
        return $this->state(fn () => [
            'label' => $label,
            'key' => CustomField::keyFrom($label),
        ]);
    }

    public function atPosition(int $position): static
    {
        return $this->state(fn () => ['position' => $position]);
    }
}
