<?php

namespace Database\Factories;

use App\Domain\Ingestion\Enums\DataSourceType;
use App\Domain\Ingestion\Enums\PullSchedule;
use App\Domain\Ingestion\IngestionTargets;
use App\Domain\Ingestion\Models\DataSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataSource>
 */
class DataSourceFactory extends Factory
{
    protected $model = DataSource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' integration',
            'description' => null,
            'type' => DataSourceType::Push->value,
            'target_module' => 'leads',
            // Live by default: a fixture that was switched off would make every
            // test that forgot to say so pass for the wrong reason.
            'is_active' => true,
            'is_sandbox' => false,
            'created_by_id' => User::factory(),
        ];
    }

    public function ofType(DataSourceType $type): static
    {
        return $this->state(fn () => ['type' => $type->value]);
    }

    public function pull(): static
    {
        return $this->ofType(DataSourceType::Pull);
    }

    /**
     * A pull source configured to fetch from somewhere.
     *
     * The host is deliberately a `.test` name: tests install a fixed resolver
     * map rather than touching DNS — see .ai/rules/integrations.md.
     */
    public function fetchingFrom(string $url, array $overrides = []): static
    {
        return $this->ofType(DataSourceType::Pull)->state(fn () => [
            'pull_url' => $url,
            'pull_records_path' => 'data',
            'pull_page_param' => 'page',
            'pull_page_size_param' => 'per_page',
            'pull_page_size' => 2,
            ...$overrides,
        ]);
    }

    public function scheduled(PullSchedule $schedule): static
    {
        return $this->state(fn () => ['pull_schedule' => $schedule->value]);
    }

    /**
     * Pointed at one of the modules the gateway actually writes into — the
     * registry rather than a string, so a fixture cannot name a target the
     * application would refuse.
     */
    public function into(string $module): static
    {
        return $this->state(function () use ($module) {
            if (! IngestionTargets::has($module)) {
                throw new \InvalidArgumentException($module.' is not an ingestion target.');
            }

            return ['target_module' => $module];
        });
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function sandbox(): static
    {
        return $this->state(fn () => ['is_sandbox' => true]);
    }
}
