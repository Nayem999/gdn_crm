<?php

namespace Database\Factories;

use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\DataSourceMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataSourceMapping>
 */
class DataSourceMappingFactory extends Factory
{
    protected $model = DataSourceMapping::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'data_source_id' => DataSource::factory(),
            'source_path' => 'title',
            'target_field' => 'last_name',
            'is_custom_field' => false,
            'transform' => null,
            'transform_options' => null,
            'default_value' => null,
            'is_required' => false,
            'position' => 0,
        ];
    }

    public function mapping(string $path, string $field): static
    {
        return $this->state(fn () => ['source_path' => $path, 'target_field' => $field]);
    }

    public function forSource(DataSource $source): static
    {
        return $this->state(fn () => ['data_source_id' => $source->id]);
    }

    public function required(): static
    {
        return $this->state(fn () => ['is_required' => true]);
    }

    public function withDefault(string $value): static
    {
        return $this->state(fn () => ['default_value' => $value]);
    }

    public function transformedBy(string $transform, ?array $options = null): static
    {
        return $this->state(fn () => ['transform' => $transform, 'transform_options' => $options]);
    }
}
