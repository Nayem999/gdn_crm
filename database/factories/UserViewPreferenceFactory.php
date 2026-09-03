<?php

namespace Database\Factories;

use App\Domain\Shared\Enums\ViewMode;
use App\Domain\Shared\Models\UserViewPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserViewPreference>
 */
class UserViewPreferenceFactory extends Factory
{
    protected $model = UserViewPreference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'module' => 'leads',
            'view_mode' => ViewMode::Table->value,
            'columns' => null,
            'pinned_columns' => null,
            'per_page' => 25,
        ];
    }
}
