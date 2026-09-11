<?php

namespace Database\Factories;

use App\Domain\Shared\Models\SavedView;
use App\Domain\Shared\SavedViews\SavedViewState;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedView>
 */
class SavedViewFactory extends Factory
{
    protected $model = SavedView::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'module' => 'leads',
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'owner_id' => User::factory(),
            'is_shared' => false,
            'state' => (new SavedViewState)->toArray(),
        ];
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['owner_id' => $user->id]);
    }

    public function forModule(string $module): static
    {
        return $this->state(fn () => ['module' => $module]);
    }

    public function shared(): static
    {
        return $this->state(fn () => ['is_shared' => true]);
    }

    public function withState(SavedViewState $state): static
    {
        return $this->state(fn () => ['state' => $state->toArray()]);
    }
}
