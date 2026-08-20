<?php

namespace App\Domain\Company\DTOs;

readonly class CompanyProfileData
{
    public function __construct(
        public string $name,
        public ?string $addressLine1,
        public ?string $addressLine2,
        public ?string $city,
        public ?string $state,
        public ?string $postalCode,
        public ?string $country,
        public string $timezone,
        public string $currency,
        public int $fiscalYearStartMonth,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'address_line_1' => $this->addressLine1,
            'address_line_2' => $this->addressLine2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'fiscal_year_start_month' => $this->fiscalYearStartMonth,
        ];
    }
}
