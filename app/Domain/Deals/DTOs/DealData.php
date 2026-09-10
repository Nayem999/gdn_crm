<?php

namespace App\Domain\Deals\DTOs;

/**
 * The fields a create or update carries, already validated.
 *
 * `stage` is deliberately absent. MoveDealStageAction is the only writer of it,
 * so no form and no payload can put a deal into a stage — the same rule
 * LeadData follows for status. `closed_at`, `close_reason` and `close_notes` are
 * absent for the same reason: CloseDealAction owns them.
 */
readonly class DealData
{
    public function __construct(
        public string $name,
        public int $accountId,
        public ?int $contactId = null,
        public ?int $pipelineId = null,
        public ?string $value = null,
        public ?string $expectedCloseDate = null,
        public ?string $description = null,
        public ?int $ownerId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        $value = fn (string $key): ?string => match (true) {
            ! array_key_exists($key, $attributes) => null,
            $attributes[$key] === null || $attributes[$key] === '' => null,
            default => (string) $attributes[$key],
        };

        $id = fn (string $key): ?int => match (true) {
            ! array_key_exists($key, $attributes) => null,
            $attributes[$key] === null || $attributes[$key] === '' => null,
            default => (int) $attributes[$key],
        };

        return new self(
            name: trim((string) ($attributes['name'] ?? '')),
            accountId: (int) ($attributes['account_id'] ?? 0),
            contactId: $id('contact_id'),
            pipelineId: $id('pipeline_id'),
            value: $value('value'),
            expectedCloseDate: $value('expected_close_date'),
            description: $value('description'),
            ownerId: $id('owner_id'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'account_id' => $this->accountId,
            'contact_id' => $this->contactId,
            'pipeline_id' => $this->pipelineId,
            'value' => $this->value,
            'expected_close_date' => $this->expectedCloseDate,
            'description' => $this->description,
        ];
    }
}
