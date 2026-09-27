<?php

namespace App\Livewire\Leads;

use App\Domain\Accounts\Actions\CreateAccountAction;
use App\Domain\Accounts\DTOs\AccountData;
use App\Domain\Accounts\Models\Account;
use App\Domain\Campaigns\Concerns\WithCampaignAttribution;
use App\Domain\Contacts\Actions\CreateContactAction;
use App\Domain\Contacts\DTOs\ContactData;
use App\Domain\Contacts\Models\Contact;
use App\Domain\CustomFields\Concerns\WithCustomFieldForm;
use App\Domain\Leads\Actions\CreateLeadAction;
use App\Domain\Leads\Actions\UpdateLeadAction;
use App\Domain\Leads\Concerns\PicksAccountAndContact;
use App\Domain\Leads\DTOs\LeadData;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\LeadDuplicates;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadAssignee;
use App\Domain\Shared\Concerns\WarnsAboutDuplicates;
use App\Domain\Shared\Duplicates\DuplicateSource;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Capture a lead, or edit one.
 *
 * There is deliberately no status field: a new lead is always New, and moving
 * it afterwards goes through ChangeLeadStatusAction so the transition rules
 * hold wherever the move came from.
 *
 * The organisation and the person can each be linked to a record on file, or
 * — optionally — created on save from a new account name and a new person
 * name, and linked then. Left blank, nothing is created until conversion.
 */
class LeadForm extends Component
{
    use AuthorizesRequests;
    use PicksAccountAndContact;
    use WarnsAboutDuplicates;
    use WithCampaignAttribution;
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

    public ?string $lead_owner_id = null;

    /**
     * The account this lead is already known to belong to, if any.
     */
    public ?string $account_id = null;

    /**
     * The person on file this lead is, if any.
     */
    public ?string $contact_id = null;

    /**
     * Creates an account on save when filled and no account is linked.
     */
    public string $new_account_name = '';

    /**
     * Creates a contact on save when filled and no contact is linked.
     */
    public string $new_contact_first_name = '';

    public string $new_contact_last_name = '';

    /**
     * One row per person the lead is being handed to.
     *
     * @var array<int, array{user_id: string, priority: string}>
     */
    public array $assignees = [];

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

        $this->assignees = [['user_id' => (string) auth()->id(), 'priority' => '']];
        $this->loadCustomFields();
    }

    public function addAssigneeRow(): void
    {
        $this->assignees[] = ['user_id' => '', 'priority' => ''];
    }

    public function removeAssigneeRow(int $index): void
    {
        // A lead needs at least one assignee, so the last row is never
        // removable — SyncLeadAssigneesAction would only refuse it anyway,
        // and doing that here means the button simply is not there to press.
        if (count($this->assignees) <= 1) {
            return;
        }

        unset($this->assignees[$index]);
        $this->assignees = array_values($this->assignees);
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
            'lead_owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'new_account_name' => ['nullable', 'string', 'max:255'],
            'new_contact_first_name' => ['nullable', 'required_with:new_contact_last_name', 'string', 'max:100'],
            'new_contact_last_name' => ['nullable', 'required_with:new_contact_first_name', 'string', 'max:100'],
            'assignees' => ['required', 'array', 'min:1'],
            'assignees.*.user_id' => ['required', 'integer', 'exists:users,id', 'distinct'],
            'assignees.*.priority' => ['nullable', 'integer', 'min:1', 'max:65535'],
            ...$this->campaignRules(),
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
            'campaign_id' => 'campaign',
            'lead_owner_id' => 'lead owner',
            'account_id' => 'account',
            'contact_id' => 'contact',
            'new_account_name' => 'new account name',
            'new_contact_first_name' => 'first name',
            'new_contact_last_name' => 'last name',
            'assignees.*.user_id' => 'assignee',
            'assignees.*.priority' => 'priority',
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
            'assignees.*.user_id.distinct' => 'The same person is on this list twice.',
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

        if (! $this->guardCampaign()) {
            return;
        }

        // `exists` proves the record is real, not that this person may link a
        // lead to it. A link the lead already carries is kept either way, so
        // editing some other field never fails over somebody else's choice.
        if ($this->account_id && (int) $this->account_id !== $lead?->account_id && $this->visibleAccount($this->account_id) === null) {
            $this->addError('account_id', 'That account is not available to you.');

            return;
        }

        if ($this->contact_id && (int) $this->contact_id !== $lead?->contact_id && $this->visibleContact($this->contact_id) === null) {
            $this->addError('contact_id', 'That contact is not available to you.');

            return;
        }

        if (! $this->guardNewRecords()) {
            return;
        }

        // One transaction, so a lead that fails to save leaves no orphaned
        // account or contact behind it.
        $saved = DB::transaction(function () use ($lead): Lead {
            $this->createNewRecords();

            $data = LeadData::fromArray($this->formFields());

            if ($lead === null) {
                $created = app(CreateLeadAction::class)($data, $this->currentUser());
                $created->saveCustomFields($this->customFields);

                return $created;
            }

            app(UpdateLeadAction::class)($lead, $data);
            $lead->saveCustomFields($this->customFields);

            return $lead;
        });

        session()->flash('status', $saved->fullName().($lead === null ? ' was captured.' : ' was saved.'));

        $this->redirectRoute('leads.show', $saved, navigate: true);
    }

    public function canCreateAccount(): bool
    {
        return $this->currentUser()->can('create', Account::class);
    }

    public function canCreateContact(): bool
    {
        return $this->currentUser()->can('create', Contact::class);
    }

    /**
     * The new-record fields only count while nothing is linked, and only for
     * somebody allowed to create that kind of record.
     */
    private function guardNewRecords(): bool
    {
        if ($this->wantsNewAccount() && ! $this->canCreateAccount()) {
            $this->addError('new_account_name', 'You cannot create accounts.');

            return false;
        }

        if ($this->wantsNewContact() && ! $this->canCreateContact()) {
            $this->addError('new_contact_first_name', 'You cannot create contacts.');

            return false;
        }

        return true;
    }

    private function wantsNewAccount(): bool
    {
        return ($this->account_id === null || $this->account_id === '') && trim($this->new_account_name) !== '';
    }

    private function wantsNewContact(): bool
    {
        return ($this->contact_id === null || $this->contact_id === '')
            && trim($this->new_contact_first_name) !== ''
            && trim($this->new_contact_last_name) !== '';
    }

    /**
     * Create the account and the person asked for, from what the lead knows,
     * and link the lead to them. The person joins the account, whether it was
     * just created or picked.
     */
    private function createNewRecords(): void
    {
        $ownerId = $this->lead_owner_id === null || $this->lead_owner_id === '' ? null : (int) $this->lead_owner_id;

        if ($this->wantsNewAccount()) {
            $account = app(CreateAccountAction::class)(new AccountData(
                name: trim($this->new_account_name),
                website: $this->blankToNull($this->website),
                email: $this->blankToNull($this->email),
                phone: $this->blankToNull($this->phone),
                addressLine1: $this->blankToNull($this->address_line_1),
                addressLine2: $this->blankToNull($this->address_line_2),
                city: $this->blankToNull($this->city),
                state: $this->blankToNull($this->state),
                postalCode: $this->blankToNull($this->postal_code),
                country: $this->blankToNull($this->country),
                ownerId: $ownerId,
            ), $this->currentUser());

            $this->account_id = (string) $account->id;
            $this->new_account_name = '';

            if ($this->blankToNull($this->company_name) === null) {
                $this->company_name = $account->name;
            }
        }

        if ($this->wantsNewContact()) {
            $contact = app(CreateContactAction::class)(new ContactData(
                firstName: trim($this->new_contact_first_name),
                lastName: trim($this->new_contact_last_name),
                jobTitle: $this->blankToNull($this->job_title),
                email: $this->blankToNull($this->email),
                phone: $this->blankToNull($this->phone),
                mobile: $this->blankToNull($this->mobile),
                addressLine1: $this->blankToNull($this->address_line_1),
                addressLine2: $this->blankToNull($this->address_line_2),
                city: $this->blankToNull($this->city),
                state: $this->blankToNull($this->state),
                postalCode: $this->blankToNull($this->postal_code),
                country: $this->blankToNull($this->country),
                accountId: $this->account_id === null || $this->account_id === '' ? null : (int) $this->account_id,
                ownerId: $ownerId,
            ), $this->currentUser());

            $this->contact_id = (string) $contact->id;
            $this->new_contact_first_name = '';
            $this->new_contact_last_name = '';
        }
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function currentUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    /**
     * @return array<int, string>
     */
    public function assigneeOptions(): array
    {
        $options = [];

        foreach (User::query()->orderBy('name')->get() as $user) {
            $options[$user->id] = $user->name;
        }

        return $options;
    }

    protected function attributedRecord(): ?Lead
    {
        return $this->lead();
    }

    /**
     * Linking an account names the company after it.
     */
    public function updatedAccountId(): void
    {
        $account = $this->visibleAccount($this->account_id);

        if ($account !== null) {
            $this->company_name = $account->name;
        }
    }

    /**
     * Linking a person fills the lead in from them — still editable — and joins
     * their account when none is chosen yet.
     */
    public function updatedContactId(): void
    {
        $contact = $this->visibleContact($this->contact_id);

        if ($contact === null) {
            return;
        }

        $this->first_name = $contact->first_name;
        $this->last_name = $contact->last_name;
        $this->job_title = $contact->job_title ?? $this->job_title;
        $this->email = $contact->email ?? $this->email;
        $this->phone = $contact->phone ?? $this->phone;
        $this->mobile = $contact->mobile ?? $this->mobile;

        if (($this->account_id === null || $this->account_id === '') && $contact->account !== null) {
            $this->account_id = (string) $contact->account->id;
            $this->company_name = $contact->account->name;
        }
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
            'users' => $this->assigneeOptions(),
            'campaigns' => $this->campaignOptions(),
            'accountOptions' => $this->accountPickerOptions($this->account_id),
            'contactOptions' => $this->contactPickerOptions($this->contact_id),
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
            'campaign_id' => $this->chosenCampaignId(),
            'lead_owner_id' => $this->lead_owner_id === null || $this->lead_owner_id === '' ? null : (int) $this->lead_owner_id,
            'account_id' => $this->account_id === null || $this->account_id === '' ? null : (int) $this->account_id,
            'contact_id' => $this->contact_id === null || $this->contact_id === '' ? null : (int) $this->contact_id,
            'assignees' => array_map(
                fn (array $row): array => [
                    'user_id' => (int) $row['user_id'],
                    'priority' => $row['priority'] === '' ? null : (int) $row['priority'],
                ],
                $this->assignees
            ),
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
        $this->fillCampaignFrom($lead);
        $this->lead_owner_id = $lead->lead_owner_id === null ? null : (string) $lead->lead_owner_id;
        $this->account_id = $lead->account_id === null ? null : (string) $lead->account_id;
        $this->contact_id = $lead->contact_id === null ? null : (string) $lead->contact_id;
        $this->assignees = $lead->assignees->map(fn (LeadAssignee $assignee): array => [
            'user_id' => (string) $assignee->user_id,
            'priority' => $assignee->priority === null ? '' : (string) $assignee->priority,
        ])->all();
    }
}
