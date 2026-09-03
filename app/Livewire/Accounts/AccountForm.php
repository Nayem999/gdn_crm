<?php

namespace App\Livewire\Accounts;

use App\Domain\Accounts\Actions\CreateAccountAction;
use App\Domain\Accounts\Actions\UpdateAccountAction;
use App\Domain\Accounts\DTOs\AccountData;
use App\Domain\Accounts\Enums\AccountSize;
use App\Domain\Accounts\Enums\Industry;
use App\Domain\Accounts\Models\Account;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * Create and edit one account.
 */
class AccountForm extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public ?int $accountId = null;

    public string $name = '';

    public ?string $legal_name = null;

    public ?string $industry = null;

    public ?string $size = null;

    public ?string $annual_revenue = null;

    public ?string $website = null;

    public ?string $email = null;

    public ?string $phone = null;

    public ?string $address_line_1 = null;

    public ?string $address_line_2 = null;

    public ?string $city = null;

    public ?string $state = null;

    public ?string $postal_code = null;

    public ?string $country = null;

    public ?string $description = null;

    public ?string $parent_id = null;

    public ?string $owner_id = null;

    public function mount(?Account $account = null): void
    {
        if ($account?->exists) {
            $this->authorize('update', $account);

            $this->accountId = $account->id;
            $this->fillFrom($account);

            return;
        }

        $this->authorize('create', Account::class);

        $this->owner_id = (string) auth()->id();
    }

    public function account(): ?Account
    {
        return $this->accountId === null ? null : Account::query()->find($this->accountId);
    }

    public function isEditing(): bool
    {
        return $this->accountId !== null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', Rule::in(array_column(Industry::cases(), 'value'))],
            'size' => ['nullable', Rule::in(array_column(AccountSize::cases(), 'value'))],
            // Money: no more than 13 digits before the point, so it fits
            // DECIMAL(15,2) rather than being silently truncated by MySQL.
            'annual_revenue' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'website' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'parent_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'owner_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'legal_name' => 'legal name',
            'annual_revenue' => 'annual revenue',
            'address_line_1' => 'address',
            'address_line_2' => 'address line 2',
            'postal_code' => 'postal code',
            'parent_id' => 'parent account',
            'owner_id' => 'owner',
        ];
    }

    public function save(): void
    {
        $account = $this->account();

        $account === null
            ? $this->authorize('create', Account::class)
            : $this->authorize('update', $account);

        $this->validate();

        $data = AccountData::fromArray($this->onlyFormFields());

        try {
            if ($account === null) {
                $created = app(CreateAccountAction::class)($data, auth()->user());

                session()->flash('status', $created->name.' was created.');

                $this->redirectRoute('accounts.show', $created, navigate: true);

                return;
            }

            app(UpdateAccountAction::class)($account, $data);
        } catch (RuntimeException $exception) {
            $this->addError('parent_id', $exception->getMessage());

            return;
        }

        session()->flash('status', $account->name.' was saved.');

        $this->redirectRoute('accounts.show', $account, navigate: true);
    }

    /**
     * Accounts that could be this one's parent.
     *
     * Scoped to what the person can see, and never itself or one of its own
     * subsidiaries — that is what keeps the hierarchy from folding into a loop.
     *
     * Keyed by id. PHP casts a numeric string key to an integer whatever we do,
     * and <x-select> stringifies keys itself, so int keys are the honest type.
     *
     * @return array<int, string>
     */
    public function parentOptions(string $term = ''): array
    {
        $account = $this->account();

        $query = Account::query()
            ->visibleTo(auth()->user())
            ->search($term)
            ->orderBy('name')
            ->limit(50);

        if ($account !== null) {
            $query->whereKeyNot($account->id);
        }

        $options = [];

        foreach ($query->get() as $candidate) {
            if ($account !== null && ! $account->canBeParentedBy($candidate)) {
                continue;
            }

            $options[$candidate->id] = $candidate->name;
        }

        return $options;
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

    public function render(): View
    {
        return view('livewire.accounts.account-form', [
            'industries' => Industry::options(),
            'sizes' => AccountSize::options(),
            'parents' => $this->parentOptions(),
            'owners' => $this->ownerOptions(),
        ])->title($this->isEditing() ? 'Edit account' : 'New account');
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyFormFields(): array
    {
        return [
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'industry' => $this->industry,
            'size' => $this->size,
            'annual_revenue' => $this->annual_revenue,
            'website' => $this->website,
            'email' => $this->email,
            'phone' => $this->phone,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'description' => $this->description,
            'parent_id' => $this->parent_id,
            'owner_id' => $this->owner_id,
        ];
    }

    private function fillFrom(Account $account): void
    {
        $this->name = $account->name;
        $this->legal_name = $account->legal_name;
        $this->industry = $account->industry;
        $this->size = $account->size;
        $this->annual_revenue = $account->annual_revenue;
        $this->website = $account->website;
        $this->email = $account->email;
        $this->phone = $account->phone;
        $this->address_line_1 = $account->address_line_1;
        $this->address_line_2 = $account->address_line_2;
        $this->city = $account->city;
        $this->state = $account->state;
        $this->postal_code = $account->postal_code;
        $this->country = $account->country;
        $this->description = $account->description;
        $this->parent_id = $account->parent_id === null ? null : (string) $account->parent_id;
        $this->owner_id = (string) $account->owner_id;
    }
}
