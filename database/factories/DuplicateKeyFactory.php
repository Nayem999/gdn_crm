<?php

namespace Database\Factories;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Shared\Models\DuplicateKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<DuplicateKey>
 */
class DuplicateKeyFactory extends Factory
{
    protected $model = DuplicateKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $contact = Contact::factory()->create();

        return [
            'keyable_type' => $contact->getMorphClass(),
            'keyable_id' => $contact->id,
            'kind' => 'email',
            'value' => fake()->unique()->safeEmail(),
        ];
    }

    public function of(Model $record, string $kind, string $value): static
    {
        return $this->state(fn () => [
            'keyable_type' => $record->getMorphClass(),
            'keyable_id' => $record->getKey(),
            'kind' => $kind,
            'value' => $value,
        ]);
    }
}
