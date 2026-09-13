<?php

namespace Database\Factories;

use App\Domain\Reports\Enums\ScheduleFrequency;
use App\Domain\Reports\Models\Report;
use App\Domain\Reports\Models\ReportSchedule;
use App\Domain\Shared\Enums\ExportFormat;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ReportSchedule>
 */
class ReportScheduleFactory extends Factory
{
    protected $model = ReportSchedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'report_id' => Report::factory(),
            'user_id' => User::factory(),
            'frequency' => ScheduleFrequency::Daily->value,
            'day_of_week' => null,
            'day_of_month' => null,
            'hour' => 8,
            'format' => ExportFormat::Pdf->value,
            'recipients' => ['finance@example.test'],
            'is_active' => true,
            'next_run_at' => null,
        ];
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }

    public function of(Report $report): static
    {
        return $this->state(fn () => ['report_id' => $report->id]);
    }

    /**
     * Due at a given moment.
     *
     * Set explicitly rather than computed, so a sweep test states the state it
     * is testing instead of depending on the clock and the frequency agreeing.
     */
    public function dueAt(Carbon|string $at): static
    {
        return $this->state(fn () => ['next_run_at' => $at]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * @param  array<int, string>  $addresses
     */
    public function sendingTo(array $addresses): static
    {
        return $this->state(fn () => ['recipients' => $addresses]);
    }

    public function as(ExportFormat $format): static
    {
        return $this->state(fn () => ['format' => $format->value]);
    }

    public function weekly(int $dayOfWeek = Carbon::MONDAY): static
    {
        return $this->state(fn () => [
            'frequency' => ScheduleFrequency::Weekly->value,
            'day_of_week' => $dayOfWeek,
        ]);
    }

    public function monthly(int $dayOfMonth = 1): static
    {
        return $this->state(fn () => [
            'frequency' => ScheduleFrequency::Monthly->value,
            'day_of_month' => $dayOfMonth,
        ]);
    }
}
