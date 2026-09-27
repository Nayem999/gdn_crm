<?php

namespace App\Livewire\Leads;

use App\Domain\Accounts\AccountDuplicates;
use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\ContactDuplicates;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Leads\Actions\ConvertLeadAction;
use App\Domain\Leads\Concerns\EditsLeadPeople;
use App\Domain\Leads\DTOs\LeadConversionData;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Duplicates\DuplicateFinder;
use App\Domain\Shared\Duplicates\DuplicateMatch;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * Turns one lead into an account, a contact and a deal.
 *
 * The screen's job is to let somebody link to records that already exist rather
 * than start second copies of them, so the 2.5 matcher runs over the lead's
 * company name and email and offers what it finds first in the pickers.
 *
 * The organisation and contact-person sections are the lead form's own
 * (EditsLeadPeople, <x-lead-people>): pick an account and any number of people
 * on file, or fill in new ones. Unlike the form, the new fields start filled
 * from the lead — converting is making records of what the lead says — and
 * stay editable. The first person becomes the deal's contact.
 */
#[Title('Convert lead')]
class LeadConvert extends Component
{
    use AuthorizesRequests;
    use EditsLeadPeople;

    #[Locked]
    public int $leadId;

    public bool $createDeal = true;

    public string $dealName = '';

    public ?string $dealValue = null;

    public ?string $dealCloseDate = null;

    public ?string $ownerId = null;

    public function mount(Lead $lead): void
    {
        $this->authorize('convert', $lead);

        $this->leadId = $lead->id;
        $this->account_id = $lead->account_id !== null ? (string) $lead->account_id : null;
        $this->new_account_name = (string) ($lead->company_name ?? $lead->fullName());
        $this->new_account_email = (string) $lead->email;
        $this->new_account_phone = (string) $lead->phone;
        $this->new_account_website = (string) $lead->website;

        // Everybody already linked to the lead; with nobody linked, the lead
        // itself as a new person, ready to check before it is created.
        $this->contacts = $lead->contacts->map(fn (Contact $contact): array => $this->blankContactRow([
            'contact_id' => (string) $contact->id,
        ]))->values()->all() ?: [$this->blankContactRow([
            'first_name' => (string) $lead->first_name,
            'last_name' => (string) $lead->last_name,
            'job_title' => (string) $lead->job_title,
            'email' => (string) $lead->email,
            'phone' => (string) $lead->phone,
            'mobile' => (string) $lead->mobile,
            'address_line_1' => (string) $lead->address_line_1,
            'address_line_2' => (string) $lead->address_line_2,
            'city' => (string) $lead->city,
            'state' => (string) $lead->state,
            'postal_code' => (string) $lead->postal_code,
            'country' => (string) $lead->country,
        ])];

        $this->dealName = $this->new_account_name.' opportunity';
        $this->dealValue = $lead->estimated_value;
        // Defaults to whoever is on the lead already, since conversion still
        // creates single-owner Account, Contact and Deal records — priority()
        // is already ordered, so this is simply the first of them.
        $this->ownerId = (string) $lead->primaryAssignee()?->id;
    }

    public function lead(): Lead
    {
        return Lead::query()
            ->visibleTo($this->currentUser())
            ->whereKey($this->leadId)
            ->firstOr(fn () => abort(404));
    }

    // -- What already exists ---------------------------------------------------

    /**
     * Accounts that look like the lead's company.
     *
     * @return array<int, DuplicateMatch>
     */
    public function accountMatches(): array
    {
        $lead = $this->lead();

        // An unsaved account carrying just what the lead knows, matched with the
        // same engine the duplicate banner uses.
        $draft = new Account([
            'name' => $this->new_account_name !== '' ? $this->new_account_name : (string) $lead->company_name,
            'email' => $lead->email,
            'phone' => $lead->phone,
        ]);

        return app(DuplicateFinder::class)->for(app(AccountDuplicates::class), $draft, $this->currentUser());
    }

    /**
     * @return array<int, DuplicateMatch>
     */
    public function contactMatches(): array
    {
        $lead = $this->lead();

        $draft = new Contact([
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'mobile' => $lead->mobile,
        ]);

        return app(DuplicateFinder::class)->for(app(ContactDuplicates::class), $draft, $this->currentUser());
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

    /**
     * @return array<int, int>
     */
    public function suggestedAccountIds(): array
    {
        return array_map(fn (DuplicateMatch $match): int => (int) $match->record->getKey(), $this->accountMatches());
    }

    /**
     * @return array<int, int>
     */
    public function suggestedContactIds(): array
    {
        return array_values(array_unique([
            ...$this->lead()->contacts()->pluck('contacts.id')->map(fn ($id): int => (int) $id)->all(),
            ...array_map(fn (DuplicateMatch $match): int => (int) $match->record->getKey(), $this->contactMatches()),
        ]));
    }

    /**
     * Converting creates the account and people by definition, so the convert
     * permission is what is needed, not accounts.create / contacts.create —
     * the same as before this screen had fields for them.
     */
    public function canCreateAccount(): bool
    {
        return $this->currentUser()->can('convert', $this->lead());
    }

    public function canCreateContact(): bool
    {
        return $this->canCreateAccount();
    }

    // -- Converting -------------------------------------------------------------

    /**
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            ...$this->peopleRules(),
            'dealName' => ['nullable', 'string', 'max:255'],
            // Matches the DECIMAL(15,2) column, so MySQL cannot silently truncate.
            'dealValue' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'dealCloseDate' => ['nullable', 'date'],
            'ownerId' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return $this->peopleAttributes();
    }

    public function convert(): void
    {
        $lead = $this->lead();

        $this->authorize('convert', $lead);
        $this->validate();

        // Conversion always ends with an account and at least one person. The
        // lead's own links stand; anything newly picked must be visible.
        if (! $this->guardPeople($lead->account_id, $lead->contacts()->pluck('contacts.id')->all(), needsAccount: true, needsPerson: true)) {
            return;
        }

        try {
            $result = app(ConvertLeadAction::class)($lead, new LeadConversionData(
                accountId: $this->pickedAccountId(),
                createDeal: $this->createDeal,
                dealName: $this->dealName,
                dealValue: $this->dealValue,
                dealCloseDate: $this->dealCloseDate,
                ownerId: $this->numeric($this->ownerId),
                newAccount: $this->newAccountDetails() ?? [],
                people: $this->contacts,
            ), $this->currentUser());
        } catch (RuntimeException $exception) {
            $this->dispatch('notify', type: 'error', message: $exception->getMessage());

            return;
        }

        session()->flash('status', $result->justConverted
            ? $lead->fullName().' was converted.'
            : $lead->fullName().' had already been converted.');

        $this->redirectRoute('accounts.show', $result->account, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.leads.lead-convert', [
            'lead' => $this->lead(),
            'owners' => $this->ownerOptions(),
        ]);
    }

    private function numeric(?string $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function currentUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
