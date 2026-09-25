<?php

namespace App\Livewire\Contacts;

use App\Domain\Accounts\Models\Account;
use App\Domain\Campaigns\Concerns\WithCampaignAttribution;
use App\Domain\Contacts\Actions\CreateContactAction;
use App\Domain\Contacts\Actions\UpdateContactAction;
use App\Domain\Contacts\ContactDuplicates;
use App\Domain\Contacts\DTOs\ContactData;
use App\Domain\Contacts\Enums\Department;
use App\Domain\Contacts\Models\Contact;
use App\Domain\CustomFields\Concerns\WithCustomFieldForm;
use App\Domain\Shared\Concerns\WarnsAboutDuplicates;
use App\Domain\Shared\Duplicates\DuplicateSource;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Create and edit one contact.
 */
class ContactForm extends Component
{
    use AuthorizesRequests;
    use WarnsAboutDuplicates;
    use WithCampaignAttribution;
    use WithCustomFieldForm;

    /**
     * How many accounts one page of the account picker returns.
     */
    public const ACCOUNTS_PER_PAGE = 25;

    #[Locked]
    public ?int $contactId = null;

    /**
     * Pre-selected when the form is opened from an account's page.
     */
    #[Url(as: 'account', except: null)]
    public ?string $account_id = null;

    public string $first_name = '';

    public string $last_name = '';

    public ?string $job_title = null;

    public ?string $department = null;

    public ?string $email = null;

    public ?string $phone = null;

    public ?string $mobile = null;

    public ?string $address_line_1 = null;

    public ?string $address_line_2 = null;

    public ?string $city = null;

    public ?string $state = null;

    public ?string $postal_code = null;

    public ?string $country = null;

    public ?string $description = null;

    public bool $is_primary = false;

    public ?string $owner_id = null;

    /**
     * The module whose custom fields this form shows.
     */
    public function customFieldModule(): string
    {
        return 'contacts';
    }

    public function mount(?Contact $contact = null): void
    {
        if ($contact?->exists) {
            $this->authorize('update', $contact);

            $this->contactId = $contact->id;
            $this->fillFrom($contact);
            $this->loadCustomFields($contact);

            return;
        }

        $this->authorize('create', Contact::class);

        $this->owner_id = (string) auth()->id();

        // An account handed in by the URL still has to be one this person can
        // see, or the picker would confirm a record they have no access to.
        if ($this->account_id !== null && $this->visibleAccount((int) $this->account_id) === null) {
            $this->account_id = null;
        }

        $this->loadCustomFields();
    }

    public function contact(): ?Contact
    {
        return $this->contactId === null ? null : Contact::query()->find($this->contactId);
    }

    public function isEditing(): bool
    {
        return $this->contactId !== null;
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
            'department' => ['nullable', Rule::in(array_column(Department::cases(), 'value'))],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'is_primary' => ['boolean'],
            'owner_id' => ['required', 'integer', 'exists:users,id'],
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
            'address_line_1' => 'address',
            'address_line_2' => 'address line 2',
            'postal_code' => 'postal code',
            'account_id' => 'account',
            'is_primary' => 'primary contact',
            'owner_id' => 'owner',
        ];
    }

    public function save(): void
    {
        $contact = $this->contact();

        $contact === null
            ? $this->authorize('create', Contact::class)
            : $this->authorize('update', $contact);

        $this->validate();

        // Checked again after validation: "exists" proves the account is real,
        // not that this person may attach someone to it.
        if ($this->account_id !== null && $this->visibleAccount((int) $this->account_id) === null) {
            $this->addError('account_id', 'That account is not available to you.');

            return;
        }

        if (! $this->guardCampaign()) {
            return;
        }

        $this->validateCustomFields($this->customFieldViewer());

        $data = ContactData::fromArray($this->formFields());

        try {
            if ($contact === null) {
                $created = app(CreateContactAction::class)($data, auth()->user());
                $created->saveCustomFields($this->customFields);

                session()->flash('status', $created->fullName().' was created.');

                $this->redirectRoute('contacts.show', $created, navigate: true);

                return;
            }

            app(UpdateContactAction::class)($contact, $data);
            $contact->saveCustomFields($this->customFields);
        } catch (RuntimeException $exception) {
            $this->addError('account_id', $exception->getMessage());

            return;
        }

        session()->flash('status', $contact->fullName().' was saved.');

        $this->redirectRoute('contacts.show', $contact, navigate: true);
    }

    /**
     * The account picker's server-side search.
     *
     * Accounts can run well past what a dropdown should hold, so this is the
     * paginated search <x-select> calls as the user types and scrolls. Scoped to
     * what the person can see, and paginated so one page is one round trip.
     *
     * Both arguments come straight from the browser and are typed loosely on
     * purpose: Tom Select sends the query as null on some paths (an empty box,
     * a preload), and a strict string parameter turned that into a 500 that the
     * dropdown swallowed into a silent "No matches found".
     *
     * @return array{options: array<int, array{value: string, label: string, description: string|null}>, hasMore: bool}
     */
    public function searchAccounts(?string $term = null, mixed $page = 1): array
    {
        $term = (string) ($term ?? '');
        $page = max(1, (int) $page);

        $query = Account::query()
            ->visibleTo(auth()->user())
            ->search($term)
            ->orderBy('name');

        // One extra row is the cheapest way to know whether more remain.
        $rows = $query
            ->limit(self::ACCOUNTS_PER_PAGE + 1)
            ->offset(($page - 1) * self::ACCOUNTS_PER_PAGE)
            ->get(['id', 'name', 'city', 'industry']);

        $hasMore = $rows->count() > self::ACCOUNTS_PER_PAGE;

        return [
            'options' => $rows
                ->take(self::ACCOUNTS_PER_PAGE)
                ->map(fn (Account $account) => [
                    'value' => (string) $account->id,
                    'label' => $account->name,
                    'description' => $account->city,
                ])
                ->values()
                ->all(),
            'hasMore' => $hasMore,
        ];
    }

    /**
     * The options rendered into the field on first paint, so an existing
     * selection still shows a name before any search has run.
     *
     * @return array<int, array<string, mixed>>
     */
    public function accountOptions(): array
    {
        $selected = $this->account_id === null ? null : $this->visibleAccount((int) $this->account_id);

        if ($selected === null) {
            return [];
        }

        return [[
            'value' => (string) $selected->id,
            'label' => $selected->name,
            'description' => $selected->city,
        ]];
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
        return app(ContactDuplicates::class);
    }

    public function duplicateIgnoreId(): ?int
    {
        return $this->contactId;
    }

    public function render(): View
    {
        return view('livewire.contacts.contact-form', [
            'departments' => Department::options(),
            'accounts' => $this->accountOptions(),
            'owners' => $this->ownerOptions(),
            'campaigns' => $this->campaignOptions(),
        ])->title($this->isEditing() ? 'Edit contact' : 'New contact');
    }

    protected function attributedRecord(): ?Contact
    {
        return $this->contact();
    }

    private function visibleAccount(int $accountId): ?Account
    {
        return Account::query()
            ->visibleTo(auth()->user())
            ->whereKey($accountId)
            ->first();
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
            'department' => $this->department,
            'email' => $this->email,
            'phone' => $this->phone,
            'mobile' => $this->mobile,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'description' => $this->description,
            'account_id' => $this->account_id,
            'is_primary' => $this->is_primary,
            'owner_id' => $this->owner_id,
            'campaign_id' => $this->chosenCampaignId(),
        ];
    }

    private function fillFrom(Contact $contact): void
    {
        $this->first_name = $contact->first_name;
        $this->last_name = $contact->last_name;
        $this->job_title = $contact->job_title;
        $this->department = $contact->department;
        $this->email = $contact->email;
        $this->phone = $contact->phone;
        $this->mobile = $contact->mobile;
        $this->address_line_1 = $contact->address_line_1;
        $this->address_line_2 = $contact->address_line_2;
        $this->city = $contact->city;
        $this->state = $contact->state;
        $this->postal_code = $contact->postal_code;
        $this->country = $contact->country;
        $this->description = $contact->description;
        $this->account_id = $contact->account_id === null ? null : (string) $contact->account_id;
        $this->is_primary = $contact->is_primary;
        $this->owner_id = (string) $contact->owner_id;
        $this->fillCampaignFrom($contact);
    }
}
