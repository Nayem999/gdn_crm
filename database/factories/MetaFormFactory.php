<?php

namespace Database\Factories;

use App\Domain\Meta\Models\MetaForm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetaForm>
 */
class MetaFormFactory extends Factory
{
    protected $model = MetaForm::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_id' => (string) fake()->unique()->randomNumber(9, true),
            'page_id' => (string) fake()->randomNumber(9, true),
            'name' => fake()->words(3, true).' enquiry',
            'status' => 'ACTIVE',
            // The shape Meta returns: a key, which is what an answer arrives
            // under, and the question as the customer read it.
            'questions' => [
                ['key' => 'email', 'label' => 'Email'],
                ['key' => 'full_name', 'label' => 'Full name'],
                ['key' => 'phone_number', 'label' => 'Phone number'],
            ],
            'last_synced_at' => now(),
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => 'ARCHIVED']);
    }
}
