<?php

namespace App\Livewire\Leads;

use App\Domain\CustomFields\Concerns\WithCustomFieldForm;
use App\Domain\Leads\Actions\CreateLeadAction;
use App\Domain\Leads\Actions\UpdateLeadAction;
use App\Domain\Leads\DTOs\LeadData;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\LeadDuplicates;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Concerns\WarnsAboutDuplicates;
use App\Domain\Shared\Duplicates\DuplicateSource;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Capture a lead, or edit one.
 *
 * There is deliberately no status field: a new lead is always New, and moving
 * it afterwards goes through ChangeLeadStatusAction so the transition rules
 * hold wherever the move came from.
 */
class LeadForm extends Component
{
    use AuthorizesRequests;
    use WarnsAboutDuplicates;
    use WithCustomFieldForm;

    #[Locked]
    public ?int $leadId = null;

    public string $first_name = '';

    public string $last_name = '';

    public ?string $job_title = null;

    public ?string $company_name = null;

    public ?string $email = null;

    public ?string $phone = null;

    public ?string $mobile = null;

    public ?string $website = null;

    public ?string $address_line_1 = null;

    public ?string $address_line_2 = null;

    public ?string $city = null;

    public ?string $state = null;

    public ?string $postal_code = null;

    public ?string $country = null;

    public ?string $source = null;

    public ?string $estimated_value = null;

    public ?string $description = null;

    public ?string $owner_id = null;

    /**
     * The module whose custom fields this form shows.
     */
    public function customFieldModule(): string
    {
        return 'leads';
    }

    public function mount(?Lead $lead = null): void
    {
        if ($lead?->exists) {
            $this->authorize('update', $lead);

            $this->leadId = $lead->id;
            $this->fillFrom($lead);
            $this->loadCustomFields($lead);

            return;
        }

        $this->authorize('create', Lead::class);

        $this->owner_id = (string) auth()->id();
        $this->loadCustomFields();
    }

    public function lead(): ?Lead
    {
        return $this->leadId === null ? null : Lead::query()->find($this->leadId);
    }

    public function isEditing(): bool
    {
        return $this->leadId !== null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            // A lead with no way of reaching them is not a lead. Either will do.
            'email' => ['nullable', 'required_without:phone', 'email', 'max:255'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'string', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', Rule::in(array_column(LeadSource::cases(), 'value'))],
            // No more than 13 digits before the point, so it fits DECIMAL(15,2)
            // rather than being silently truncated by MySQL.
            'estimated_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'description' => ['nullable', 'string', 'max:2000'],
            'owner_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'first_name' => 'first name',
            'last_name' => 'last name',
            'job_title' => 'job title',
            'company_name' => 'company',
            'address_line_1' => 'address',
            'address_line_2' => 'address line 2',
            'postal_code' => 'postal code',
            'estimated_value' => 'estimated value',
            'owner_id' => 'owner',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'email.required_without' => 'Give an email address or a phone number.',
            'phone.required_without' => 'Give a phone number or an email address.',
        ];
    }

    public function save(): void
    {
        $lead = $this->lead();

        $lead === null
            ? $this->authorize('create', Lead::class)
            : $this->authorize('update', $lead);

        $this->validate();
        $this->validateCustomFields($this->customFieldViewer());

        $data = LeadData::fromArray($this->formFields());

        if ($lead === null) {
            $created = app(CreateLeadAction::class)($data, auth()->user());
            $created->saveCustomFields($this->customFields);

            session()->flash('status', $created->fullName().' was captured.');

            $this->redirectRoute('leads.show', $created, navigate: true);

            return;
        }

        app(UpdateLeadAction::class)($lead, $data);
        $lead->saveCustomFields($this->customFields);

        session()->flash('status', $lead->fullName().' was saved.');

        $this->redirectRoute('leads.show', $lead, navigate: true);
    }

    /**
     * @return array<int, string>
     */
    public function ownerOptions(): array
    {
        $options = [];

        foreach (User::query()->orderBy('name')->get() as $user) {
            $options[$user->id] = $user->name;
        }

        return $options;
    }

    public function duplicateSource(): ?DuplicateSource
    {
        return app(LeadDuplicates::class);
    }

    public function duplicateIgnoreId(): ?int
    {
        return $this->leadId;
    }

    public function render(): View
    {
        return view('livewire.leads.lead-form', [
            'sources' => LeadSource::options(),
            'owners' => $this->ownerOptions(),
        ])->title($this->isEditing() ? 'Edit lead' : 'Capture lead');
    }

    /**
     * @return array<string, mixed>
     */
    private function formFields(): array
    {
        return [
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'job_title' => $this->job_title,
            'company_name' => $this->company_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'mobile' => $this->mobile,
            'website' => $this->website,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'source' => $this->source,
            'estimated_value' => $this->estimated_value,
            'description' => $this->description,
            'owner_id' => $this->owner_id,
        ];
    }

    private function fillFrom(Lead $lead): void
    {
        $this->first_name = $lead->first_name;
        $this->last_name = $lead->last_name;
        $this->job_title = $lead->job_title;
        $this->company_name = $lead->company_name;
        $this->email = $lead->email;
        $this->phone = $lead->phone;
        $this->mobile = $lead->mobile;
        $this->website = $lead->website;
        $this->address_line_1 = $lead->address_line_1;
        $this->address_line_2 = $lead->address_line_2;
        $this->city = $lead->city;
        $this->state = $lead->state;
        $this->postal_code = $lead->postal_code;
        $this->country = $lead->country;
        $this->source = $lead->source;
        $this->estimated_value = $lead->estimated_value;
        $this->description = $lead->description;
        $this->owner_id = (string) $lead->owner_id;
    }
}
