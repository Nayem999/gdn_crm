<?php

namespace App\Livewire\Company;

use App\Domain\Company\Actions\UpdateCompanyProfileAction;
use App\Domain\Company\DTOs\CompanyProfileData;
use App\Domain\Company\Models\Company;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Title('Company Profile')]
class CompanyProfileForm extends Component
{
    use AuthorizesRequests, WithFileUploads;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:255')]
    public ?string $addressLine1 = null;

    #[Validate('nullable|string|max:255')]
    public ?string $addressLine2 = null;

    #[Validate('nullable|string|max:100')]
    public ?string $city = null;

    #[Validate('nullable|string|max:100')]
    public ?string $state = null;

    #[Validate('nullable|string|max:20')]
    public ?string $postalCode = null;

    #[Validate('nullable|string|max:100')]
    public ?string $country = null;

    #[Validate('required|timezone')]
    public string $timezone = 'UTC';

    #[Validate('required|string|size:3')]
    public string $currency = 'USD';

    #[Validate('required|integer|between:1,12')]
    public int $fiscalYearStartMonth = 1;

    /** @var TemporaryUploadedFile|null */
    #[Validate('nullable|image|max:2048')]
    public $logo = null;

    public function mount(): void
    {
        $this->authorize('view', Company::current());

        $company = Company::current();

        $this->name = $company->name;
        $this->addressLine1 = $company->address_line_1;
        $this->addressLine2 = $company->address_line_2;
        $this->city = $company->city;
        $this->state = $company->state;
        $this->postalCode = $company->postal_code;
        $this->country = $company->country;
        $this->timezone = $company->timezone;
        $this->currency = $company->currency;
        $this->fiscalYearStartMonth = $company->fiscal_year_start_month;
    }

    public function save(UpdateCompanyProfileAction $action): void
    {
        $company = Company::current();

        $this->authorize('update', $company);

        $validated = $this->validate();

        $data = new CompanyProfileData(
            name: $validated['name'],
            addressLine1: $validated['addressLine1'],
            addressLine2: $validated['addressLine2'],
            city: $validated['city'],
            state: $validated['state'],
            postalCode: $validated['postalCode'],
            country: $validated['country'],
            timezone: $validated['timezone'],
            currency: strtoupper($validated['currency']),
            fiscalYearStartMonth: $validated['fiscalYearStartMonth'],
        );

        $action($company, $data, $this->logo);

        $this->logo = null;

        $this->dispatch('company-profile-saved');
    }

    /**
     * @return array<string, string>
     */
    public function timezoneOptions(): array
    {
        return collect(DateTimeZone::listIdentifiers())
            ->mapWithKeys(fn (string $timezone) => [$timezone => str_replace('_', ' ', $timezone)])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function currencyOptions(): array
    {
        return [
            'USD' => 'USD — US Dollar',
            'EUR' => 'EUR — Euro',
            'GBP' => 'GBP — British Pound',
            'CAD' => 'CAD — Canadian Dollar',
            'AUD' => 'AUD — Australian Dollar',
            'INR' => 'INR — Indian Rupee',
            'BDT' => 'BDT — Bangladeshi Taka',
            'PKR' => 'PKR — Pakistani Rupee',
            'AED' => 'AED — UAE Dirham',
            'SAR' => 'SAR — Saudi Riyal',
            'SGD' => 'SGD — Singapore Dollar',
            'JPY' => 'JPY — Japanese Yen',
            'CNY' => 'CNY — Chinese Yuan',
            'ZAR' => 'ZAR — South African Rand',
            'NGN' => 'NGN — Nigerian Naira',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function monthOptions(): array
    {
        return [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
        ];
    }

    public function existingLogoUrl(): ?string
    {
        return Company::current()->logoUrl();
    }

    public function render(): View
    {
        return view('livewire.company.company-profile-form');
    }
}
