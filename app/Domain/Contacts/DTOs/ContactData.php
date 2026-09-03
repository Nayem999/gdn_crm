<?php

namespace App\Domain\Contacts\DTOs;

/**
 * The fields a create or update carries, already validated.
 */
readonly class ContactData
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public ?string $jobTitle = null,
        public ?string $department = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $mobile = null,
        public ?string $addressLine1 = null,
        public ?string $addressLine2 = null,
        public ?string $city = null,
        public ?string $state = null,
        public ?string $postalCode = null,
        public ?string $country = null,
        public ?string $description = null,
        public ?int $accountId = null,
        public bool $isPrimary = false,
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

        $id = fn (string $key): ?int => isset($attributes[$key]) && $attributes[$key] !== ''
            ? (int) $attributes[$key]
            : null;

        return new self(
            firstName: trim((string) ($attributes['first_name'] ?? '')),
            lastName: trim((string) ($attributes['last_name'] ?? '')),
            jobTitle: $value('job_title'),
            department: $value('department'),
            email: $value('email'),
            phone: $value('phone'),
            mobile: $value('mobile'),
            addressLine1: $value('address_line_1'),
            addressLine2: $value('address_line_2'),
            city: $value('city'),
            state: $value('state'),
            postalCode: $value('postal_code'),
            country: $value('country'),
            description: $value('description'),
            accountId: $id('account_id'),
            isPrimary: (bool) ($attributes['is_primary'] ?? false),
            ownerId: $id('owner_id'),
        );
    }

    /**
     * The stored columns, keeping nulls so an update clears a field the user
     * emptied. is_primary is deliberately absent: it is owned by
     * SetPrimaryContactAction, never written straight from a form.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'job_title' => $this->jobTitle,
            'department' => $this->department,
            'email' => $this->email,
            'phone' => $this->phone,
            'mobile' => $this->mobile,
            'address_line_1' => $this->addressLine1,
            'address_line_2' => $this->addressLine2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
            'description' => $this->description,
            'account_id' => $this->accountId,
        ];
    }
}
