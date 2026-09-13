<?php

namespace Database\Factories;

use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\IntegrationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<IntegrationEvent>
 */
class IntegrationEventFactory extends Factory
{
    protected $model = IntegrationEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'data_source_id' => DataSource::factory(),
            'status' => IntegrationEventStatus::Received->value,
            // A string, not an array: the column holds the bytes that arrived.
            'payload' => json_encode(['id' => fake()->uuid(), 'title' => fake()->sentence(3)]),
            'headers' => ['content-type' => 'application/json'],
            'mapped_output' => null,
            'external_id' => null,
            'record_type' => null,
            'record_id' => null,
            'outcome' => null,
            'ip_address' => fake()->ipv4(),
            'signature_verified' => true,
            'is_sandbox' => false,
            'attempts' => 0,
            'error' => null,
            'received_at' => now(),
            'processed_at' => null,
        ];
    }

    /**
     * Named forSource rather than for: Factory::for() already means "belongs to
     * this relation", and overriding it would break every other use of it.
     */
    public function forSource(DataSource $source): static
    {
        return $this->state(fn () => [
            'data_source_id' => $source->id,
            // The event copies what applied at the time rather than joining
            // back to a source that may since have been reconfigured.
            'is_sandbox' => $source->is_sandbox,
        ]);
    }

    public function withStatus(IntegrationEventStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status->value,
            'processed_at' => $status->isSettled() ? now() : null,
        ]);
    }

    public function failed(string $error = 'The payload had no email address.'): static
    {
        return $this->state(fn () => [
            'status' => IntegrationEventStatus::Failed->value,
            'error' => $error,
            'attempts' => 1,
            'processed_at' => now(),
        ]);
    }

    /**
     * A delivery that produced a record. What locks a source to its target
     * module, so a fixture proving that needs one of these.
     */
    public function wrote(Model $record): static
    {
        return $this->state(fn () => [
            'status' => IntegrationEventStatus::Processed->value,
            'record_type' => $record->getMorphClass(),
            'record_id' => $record->getKey(),
            'outcome' => 'created',
            'processed_at' => now(),
        ]);
    }

    public function receivedAt(Carbon|string $at): static
    {
        return $this->state(fn () => [
            'received_at' => $at instanceof Carbon ? $at : Carbon::parse($at),
        ]);
    }
}
