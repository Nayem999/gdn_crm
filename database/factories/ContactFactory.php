<?php

namespace Database\Factories;

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Enums\Department;
use App\Domain\Contacts\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'job_title' => fake()->jobTitle(),
            'department' => fake()->randomElement(Department::cases())->value,
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'mobile' => fake()->phoneNumber(),
            'city' => fake()->city(),
            'country' => fake()->country(),
            'description' => fake()->sentence(),
            'account_id' => null,
            'is_primary' => false,
            'owner_id' => User::factory(),
        ];
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['owner_id' => $user->id]);
    }

    public function forAccount(Account $account): static
    {
        return $this->state(fn () => ['account_id' => $account->id]);
    }

    /**
     * The primary contact for its account. Only meaningful with an account.
     */
    public function primary(): static
    {
        return $this->state(fn () => ['is_primary' => true]);
    }

    public function department(Department $department): static
    {
        return $this->state(fn () => ['department' => $department->value]);
    }

    public function named(string $first, string $last): static
    {
        return $this->state(fn () => ['first_name' => $first, 'last_name' => $last]);
    }
}
