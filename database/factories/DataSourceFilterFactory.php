<?php

namespace Database\Factories;

use App\Domain\Ingestion\Enums\PayloadFilterOperator;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\DataSourceFilter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataSourceFilter>
 */
class DataSourceFilterFactory extends Factory
{
    protected $model = DataSourceFilter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'data_source_id' => DataSource::factory(),
            'path' => 'event',
            'operator' => PayloadFilterOperator::Equals->value,
            'value' => 'task.created',
            'position' => 0,
        ];
    }

    public function forSource(DataSource $source): static
    {
        return $this->state(fn () => ['data_source_id' => $source->id]);
    }

    public function rule(string $path, PayloadFilterOperator $operator, ?string $value = null): static
    {
        return $this->state(fn () => [
            'path' => $path,
            'operator' => $operator->value,
            'value' => $value,
        ]);
    }
}
