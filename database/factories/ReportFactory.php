<?php

namespace Database\Factories;

use App\Domain\Reports\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Deals by stage',
            'description' => null,
            'source' => 'deals',
            'definition' => [
                'source' => 'deals',
                'dimensions' => ['stage'],
                'measures' => ['count', 'value'],
                'filters' => ['match' => 'all', 'conditions' => [], 'groups' => []],
                'grain' => 'month',
            ],
            'chart_type' => 'table',
            'owner_id' => User::factory(),
            'is_shared' => false,
            'is_standard' => false,
        ];
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['owner_id' => $user->id]);
    }

    public function shared(): static
    {
        return $this->state(fn () => ['is_shared' => true]);
    }

    /**
     * A built-in, which cannot be removed.
     *
     * The slug goes with it: a standard report is addressed by name in the rest
     * of the application, and one without a slug could not be.
     */
    public function standard(string $slug): static
    {
        return $this->state(fn () => [
            'is_standard' => true,
            'is_shared' => true,
            'slug' => $slug,
            'owner_id' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function asking(string $source, array $definition): static
    {
        return $this->state(fn () => [
            'source' => $source,
            'definition' => [...$definition, 'source' => $source],
        ]);
    }
}
