<?php

namespace App\Domain\Accounts\DTOs;

/**
 * The fields a create or update carries, already validated.
 */
readonly class AccountData
{
    public function __construct(
        public string $name,
        public ?string $legalName = null,
        public ?string $industry = null,
        public ?string $size = null,
        public ?string $annualRevenue = null,
        public ?string $website = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $addressLine1 = null,
        public ?string $addressLine2 = null,
        public ?string $city = null,
        public ?string $state = null,
        public ?string $postalCode = null,
        public ?string $country = null,
        public ?string $description = null,
        public ?int $parentId = null,
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

        return new self(
            name: trim((string) ($attributes['name'] ?? '')),
            legalName: $value('legal_name'),
            industry: $value('industry'),
            size: $value('size'),
            annualRevenue: $value('annual_revenue'),
            website: $value('website'),
            email: $value('email'),
            phone: $value('phone'),
            addressLine1: $value('address_line_1'),
            addressLine2: $value('address_line_2'),
            city: $value('city'),
            state: $value('state'),
            postalCode: $value('postal_code'),
            country: $value('country'),
            description: $value('description'),
            parentId: isset($attributes['parent_id']) && $attributes['parent_id'] !== ''
                ? (int) $attributes['parent_id']
                : null,
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
        return array_filter([
            'name' => $this->name,
            'legal_name' => $this->legalName,
            'industry' => $this->industry,
            'size' => $this->size,
            'annual_revenue' => $this->annualRevenue,
            'website' => $this->website,
            'email' => $this->email,
            'phone' => $this->phone,
            'address_line_1' => $this->addressLine1,
            'address_line_2' => $this->addressLine2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
            'description' => $this->description,
            'parent_id' => $this->parentId,
            'owner_id' => $this->ownerId,
        ], fn (mixed $value) => $value !== null);
    }

    /**
     * The same attributes, but keeping nulls, so an update can clear a field
     * the user emptied rather than leaving the old value in place.
     *
     * @return array<string, mixed>
     */
    public function toUpdateAttributes(): array
    {
        return [
            'name' => $this->name,
            'legal_name' => $this->legalName,
            'industry' => $this->industry,
            'size' => $this->size,
            'annual_revenue' => $this->annualRevenue,
            'website' => $this->website,
            'email' => $this->email,
            'phone' => $this->phone,
            'address_line_1' => $this->addressLine1,
            'address_line_2' => $this->addressLine2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
            'description' => $this->description,
            'parent_id' => $this->parentId,
        ];
    }
}
