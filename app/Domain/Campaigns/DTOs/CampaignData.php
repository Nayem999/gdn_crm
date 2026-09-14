<?php

namespace App\Domain\Campaigns\DTOs;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Enums\CampaignType;

/**
 * A campaign as a form submitted it, already normalised.
 */
readonly class CampaignData
{
    public function __construct(
        public string $name,
        public CampaignType $type,
        public CampaignStatus $status,
        public ?string $description = null,
        public ?string $startDate = null,
        public ?string $endDate = null,
        public ?float $budget = null,
        public ?float $actualCost = null,
        public ?float $expectedRevenue = null,
        public ?string $code = null,
        public ?int $ownerId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            name: trim((string) ($attributes['name'] ?? '')),
            type: CampaignType::tryFrom((string) ($attributes['type'] ?? '')) ?? CampaignType::Other,
            status: CampaignStatus::tryFrom((string) ($attributes['status'] ?? '')) ?? CampaignStatus::Planned,
            description: self::text($attributes, 'description'),
            startDate: self::text($attributes, 'start_date'),
            endDate: self::text($attributes, 'end_date'),
            budget: self::money($attributes, 'budget'),
            actualCost: self::money($attributes, 'actual_cost'),
            expectedRevenue: self::money($attributes, 'expected_revenue'),
            // Uppercased and trimmed: a campaign code is a code, and "q1-push"
            // and "Q1-PUSH" being two campaigns is how attribution stops adding
            // up.
            code: self::code($attributes['code'] ?? null),
            ownerId: isset($attributes['owner_id']) && $attributes['owner_id'] !== ''
                ? (int) $attributes['owner_id']
                : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'description' => $this->description,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'budget' => $this->budget,
            'actual_cost' => $this->actualCost,
            'expected_revenue' => $this->expectedRevenue,
            'code' => $this->code,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function text(array $attributes, string $key): ?string
    {
        $value = $attributes[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function money(array $attributes, string $key): ?float
    {
        $value = $attributes[$key] ?? null;

        // A blank field means "not recorded", which is not the same as zero: a
        // campaign with no budget set should not read as one budgeted at
        // nothing, because the second makes every percentage infinite.
        return $value === null || $value === '' ? null : (float) $value;
    }

    private static function code(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return strtoupper(trim($value));
    }
}
