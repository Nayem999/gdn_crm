<?php

namespace App\Livewire\Leads;

use App\Domain\Accounts\AccountDuplicates;
use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\ContactDuplicates;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Leads\Actions\ConvertLeadAction;
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
 * company name and email and offers what it finds.
 */
#[Title('Convert lead')]
class LeadConvert extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $leadId;

    /**
     * Blank means "create a new one from the lead".
     */
    public ?string $accountId = null;

    public string $accountName = '';

    public ?string $contactId = null;

    public bool $createDeal = true;

    public string $dealName = '';

    public ?string $dealValue = null;

    public ?string $dealCloseDate = null;

    public ?string $ownerId = null;

    public function mount(Lead $lead): void
    {
        $this->authorize('convert', $lead);

        $this->leadId = $lead->id;
        $this->accountName = (string) ($lead->company_name ?? $lead->fullName());
        $this->dealName = $this->accountName.' opportunity';
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
            'name' => $this->accountName !== '' ? $this->accountName : (string) $lead->company_name,
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
     * @return array<string, string>
     */
    public function accountOptions(): array
    {
        $options = ['' => 'Create a new account'];

        foreach ($this->accountMatches() as $match) {
            /** @var Account $account */
            $account = $match->record;
            $options[(string) $account->id] = $account->name;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function contactOptions(): array
    {
        $options = ['' => 'Create a new contact'];

        foreach ($this->contactMatches() as $match) {
            /** @var Contact $contact */
            $contact = $match->record;
            $options[(string) $contact->id] = $contact->fullName();
        }

        return $options;
    }

    // -- Converting -------------------------------------------------------------

    /**
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            'accountName' => ['required_without:accountId', 'nullable', 'string', 'max:255'],
            'dealName' => ['nullable', 'string', 'max:255'],
            // Matches the DECIMAL(15,2) column, so MySQL cannot silently truncate.
            'dealValue' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'dealCloseDate' => ['nullable', 'date'],
            'ownerId' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function convert(): void
    {
        $lead = $this->lead();

        $this->authorize('convert', $lead);
        $this->validate();

        // The chosen records are re-checked against what this person can see:
        // "exists" proves a record is real, never that they may reach it.
        $accountId = $this->numeric($this->accountId);
        $contactId = $this->numeric($this->contactId);

        if ($accountId !== null && ! Account::query()->visibleTo($this->currentUser())->whereKey($accountId)->exists()) {
            $this->addError('accountId', 'That account is not one you can use.');

            return;
        }

        if ($contactId !== null && ! Contact::query()->visibleTo($this->currentUser())->whereKey($contactId)->exists()) {
            $this->addError('contactId', 'That contact is not one you can use.');

            return;
        }

        try {
            $result = app(ConvertLeadAction::class)($lead, new LeadConversionData(
                accountId: $accountId,
                accountName: $this->accountName,
                contactId: $contactId,
                createDeal: $this->createDeal,
                dealName: $this->dealName,
                dealValue: $this->dealValue,
                dealCloseDate: $this->dealCloseDate,
                ownerId: $this->numeric($this->ownerId),
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
            'accounts' => $this->accountOptions(),
            'contacts' => $this->contactOptions(),
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
