<?php

namespace Database\Factories;

use App\Domain\Reports\Models\DashboardWidget;
use App\Domain\Reports\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardWidget>
 */
class DashboardWidgetFactory extends Factory
{
    protected $model = DashboardWidget::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'report_id' => Report::factory(),
            'title' => null,
            'chart_type' => null,
            'width' => 1,
            'position' => 0,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }

    public function showing(Report $report): static
    {
        return $this->state(fn () => ['report_id' => $report->id]);
    }

    public function at(int $position): static
    {
        return $this->state(fn () => ['position' => $position]);
    }
}
