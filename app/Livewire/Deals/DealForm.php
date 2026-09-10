<?php

namespace App\Livewire\Deals;

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Actions\CreateDealAction;
use App\Domain\Deals\Actions\UpdateDealAction;
use App\Domain\Deals\DTOs\DealData;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\Pipeline;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Create and edit one deal.
 *
 * There is no stage control. MoveDealStageAction is the only writer of stage,
 * and the board and the detail page are where a deal is moved — a form that
 * could set it would be a second path into the same column, and the two would
 * eventually disagree about the closing stamp.
 */
class DealForm extends Component
{
    use AuthorizesRequests;

    /**
     * How many rows one page of a picker returns.
     */
    public const PER_PAGE = 25;

    #[Locked]
    public ?int $dealId = null;

    public string $name = '';

    /**
     * Pre-selected when the form is opened from an account's page.
     */
    #[Url(as: 'account', except: null)]
    public ?string $account_id = null;

    public ?string $contact_id = null;

    public ?string $pipeline_id = null;

    public ?string $value = null;

    public ?string $expected_close_date = null;

    public ?string $description = null;

    public ?string $owner_id = null;

    public function mount(?Deal $deal = null): void
    {
        if ($deal?->exists) {
            $this->authorize('update', $deal);

            $this->dealId = $deal->id;
            $this->name = $deal->name;
            $this->account_id = (string) $deal->account_id;
            $this->contact_id = $deal->contact_id === null ? null : (string) $deal->contact_id;
            $this->pipeline_id = $deal->pipeline_id === null ? null : (string) $deal->pipeline_id;
            $this->value = $deal->value === null ? null : (string) $deal->value;
            $this->expected_close_date = $deal->expected_close_date?->format('Y-m-d');
            $this->description = $deal->description;
            $this->owner_id = (string) $deal->owner_id;

            return;
        }

        $this->authorize('create', Deal::class);

        $this->owner_id = (string) auth()->id();
        $this->pipeline_id = ($id = Pipeline::default()?->id) === null ? null : (string) $id;
    }

    public function deal(): ?Deal
    {
        return $this->dealId === null
            ? null
            : Deal::query()->whereKey($this->dealId)->first();
    }

    public function isEditing(): bool
    {
        return $this->dealId !== null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'pipeline_id' => ['nullable', 'integer', 'exists:pipelines,id'],
            // Capped so MySQL cannot silently truncate a DECIMAL(15,2) that
            // does not fit, the same guard AccountForm puts on revenue.
            'value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'expected_close_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'name' => 'deal name',
            'account_id' => 'account',
            'contact_id' => 'contact',
            'pipeline_id' => 'pipeline',
            'owner_id' => 'owner',
            'expected_close_date' => 'expected close date',
        ];
    }

    /**
     * Choosing another account clears the contact: the picker below it lists
     * that account's people, and keeping a contact from the previous one is how
     * a deal ends up pointing at somebody who works elsewhere.
     */
    public function updatedAccountId(): void
    {
        $this->contact_id = null;
    }

    public function save(): void
    {
        $deal = $this->deal();

        if ($deal === null) {
            $this->authorize('create', Deal::class);
        } else {
            $this->authorize('update', $deal);
        }

        $this->validate();

        // Checked again after validation: `exists` proves the account is real,
        // never that this person may reach it.
        if (! Account::query()->visibleTo(auth()->user())->whereKey((int) $this->account_id)->exists()) {
            $this->addError('account_id', 'That account is not one you can work with.');

            return;
        }

        $data = DealData::fromArray([
            'name' => $this->name,
            'account_id' => $this->account_id,
            'contact_id' => $this->contact_id,
            'pipeline_id' => $this->pipeline_id,
            'value' => $this->value,
            'expected_close_date' => $this->expected_close_date,
            'description' => $this->description,
            'owner_id' => $this->canAssign($deal) ? $this->owner_id : $deal?->owner_id,
        ]);

        try {
            $saved = $deal === null
                ? app(CreateDealAction::class)($data, $this->currentUser())
                : app(UpdateDealAction::class)($deal, $data);
        } catch (RuntimeException $exception) {
            $this->addError('name', $exception->getMessage());

            return;
        }

        session()->flash('status', $saved->name.' was saved.');

        $this->redirectRoute('deals.show', ['deal' => $saved->id], navigate: true);
    }

    /**
     * Whether this person may choose the owner. Without it the field is not
     * rendered and a submitted owner is ignored server-side.
     */
    public function canAssign(?Deal $deal = null): bool
    {
        $deal ??= $this->deal();

        return $deal === null
            ? auth()->user()?->can('create', Deal::class) === true
            : auth()->user()?->can('assign', $deal) === true;
    }

    // -- Pickers -------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function searchAccounts(?string $term = null, mixed $page = 1): array
    {
        // Typed loosely because Tom Select sends null on a preload and on a
        // cleared box; a string parameter turns that into a silent 500.
        $term = (string) ($term ?? '');
        $page = max(1, (int) $page);

        $rows = Account::query()
            ->visibleTo(auth()->user())
            ->search($term)
            ->orderBy('name')
            ->limit(self::PER_PAGE + 1)
            ->offset(($page - 1) * self::PER_PAGE)
            ->get(['id', 'name', 'city']);

        return [
            'options' => $rows->take(self::PER_PAGE)
                ->map(fn (Account $account) => [
                    'value' => (string) $account->id,
                    'label' => $account->name,
                    'description' => $account->city,
                ])
                ->values()
                ->all(),
            'hasMore' => $rows->count() > self::PER_PAGE,
        ];
    }

    /**
     * The chosen account's people, which is why it depends on that field.
     *
     * @return array<string, mixed>
     */
    public function searchContacts(?string $term = null, mixed $page = 1): array
    {
        $term = (string) ($term ?? '');
        $page = max(1, (int) $page);

        if ($this->account_id === null || $this->account_id === '') {
            return ['options' => [], 'hasMore' => false];
        }

        $rows = Contact::query()
            ->visibleTo(auth()->user())
            ->where('account_id', (int) $this->account_id)
            ->search($term)
            ->orderBy('last_name')
            ->limit(self::PER_PAGE + 1)
            ->offset(($page - 1) * self::PER_PAGE)
            ->get(['id', 'first_name', 'last_name', 'job_title', 'account_id']);

        return [
            'options' => $rows->take(self::PER_PAGE)
                ->map(fn (Contact $contact) => [
                    'value' => (string) $contact->id,
                    'label' => $contact->fullName(),
                    'description' => $contact->job_title,
                ])
                ->values()
                ->all(),
            'hasMore' => $rows->count() > self::PER_PAGE,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pipelineOptions(): array
    {
        return Pipeline::query()->with('stages')->ordered()->get()
            ->map(fn (Pipeline $pipeline) => [
                'value' => (string) $pipeline->id,
                'label' => $pipeline->name,
                'description' => $pipeline->is_default ? 'Default' : null,
            ])
            ->values()
            ->all();
    }

    /**
     * PHP casts a numeric string array key back to int, so the id keys here are
     * ints however they are written — typed as such rather than pretending.
     *
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

    private function currentUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    public function render(): View
    {
        return view('livewire.deals.deal-form')
            ->title($this->isEditing() ? 'Edit deal' : 'Add deal');
    }
}
