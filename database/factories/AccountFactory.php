<?php

namespace Database\Factories;

use App\Domain\Accounts\Enums\AccountSize;
use App\Domain\Accounts\Enums\Industry;
use App\Domain\Accounts\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'legal_name' => $name.' '.fake()->companySuffix(),
            'industry' => fake()->randomElement(Industry::cases())->value,
            'size' => fake()->randomElement(AccountSize::cases())->value,
            'annual_revenue' => fake()->randomFloat(2, 10000, 50000000),
            'website' => 'https://'.fake()->domainName(),
            'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'address_line_1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'postal_code' => fake()->postcode(),
            'country' => fake()->country(),
            'description' => fake()->sentence(),
            'parent_id' => null,
            'owner_id' => User::factory(),
        ];
    }

    /**
     * An account belonging to a particular person.
     */
    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['owner_id' => $user->id]);
    }

    /**
     * A subsidiary of another account.
     */
    public function childOf(Account $parent): static
    {
        return $this->state(fn () => ['parent_id' => $parent->id]);
    }

    public function industry(Industry $industry): static
    {
        return $this->state(fn () => ['industry' => $industry->value]);
    }

    public function size(AccountSize $size): static
    {
        return $this->state(fn () => ['size' => $size->value]);
    }
}
