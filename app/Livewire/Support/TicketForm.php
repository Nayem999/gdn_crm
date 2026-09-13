<?php

namespace App\Livewire\Support;

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\CustomFields\Concerns\WithCustomFieldForm;
use App\Domain\Support\Actions\CreateTicketAction;
use App\Domain\Support\Actions\UpdateTicketAction;
use App\Domain\Support\DTOs\TicketData;
use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketSource;
use App\Domain\Support\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Raise or edit one ticket.
 *
 * There is no status control. ChangeTicketStatusAction is the only writer of
 * `status`, and the board and the ticket page are where one is moved — a form
 * that could set it would be a second path to "resolved", and the two would
 * eventually disagree about `resolved_at`.
 */
class TicketForm extends Component
{
    use AuthorizesRequests;
    use WithCustomFieldForm;

    /**
     * How many rows one page of a picker returns.
     */
    public const PER_PAGE = 25;

    #[Locked]
    public ?int $ticketId = null;

    public string $subject = '';

    public ?string $description = null;

    public string $priority = '2';

    public string $source = 'manual';

    /**
     * Pre-selected when the form is opened from a contact's or account's page.
     */
    #[Url(as: 'contact', except: null)]
    public ?string $contact_id = null;

    #[Url(as: 'account', except: null)]
    public ?string $account_id = null;

    public ?string $owner_id = null;

    public function mount(?Ticket $ticket = null): void
    {
        if ($ticket?->exists) {
            $this->authorize('update', $ticket);

            $this->ticketId = $ticket->id;
            $this->subject = $ticket->subject;
            $this->description = $ticket->description;
            $this->priority = (string) $ticket->priority()->value;
            $this->source = $ticket->source()->value;
            $this->contact_id = $ticket->contact_id === null ? null : (string) $ticket->contact_id;
            $this->account_id = $ticket->account_id === null ? null : (string) $ticket->account_id;
            $this->owner_id = (string) $ticket->owner_id;

            $this->loadCustomFields($ticket);

            return;
        }

        $this->authorize('create', Ticket::class);

        $this->owner_id = (string) auth()->id();
        $this->loadCustomFields();
    }

    public function ticket(): ?Ticket
    {
        return $this->ticketId === null
            ? null
            : Ticket::query()->whereKey($this->ticketId)->first();
    }

    public function isEditing(): bool
    {
        return $this->ticketId !== null;
    }

    public function customFieldModule(): string
    {
        return 'tickets';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'priority' => ['required', 'integer', Rule::in(array_keys(TicketPriority::options()))],
            'source' => ['required', 'string', Rule::in(array_keys(TicketSource::options()))],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'owner_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'contact_id' => 'contact',
            'account_id' => 'account',
            'owner_id' => 'agent',
            'source' => 'how it came in',
        ];
    }

    /**
     * Choosing another account clears the contact: the picker below it lists
     * that account's people, and keeping one from the previous account is how a
     * ticket ends up on the wrong customer's history.
     */
    public function updatedAccountId(): void
    {
        $this->contact_id = null;
    }

    public function save(): void
    {
        $ticket = $this->ticket();

        $ticket === null
            ? $this->authorize('create', Ticket::class)
            : $this->authorize('update', $ticket);

        $this->validate();
        // Custom fields validate against their own definitions, which know
        // about lookups and visibility conditions that a static rule array
        // cannot express.
        $this->validateCustomFields($this->customFieldViewer());

        // Checked again after validation: `exists` proves the account is real,
        // never that this person may reach it.
        if ($this->account_id !== null
            && ! Account::query()->visibleTo(auth()->user())->whereKey((int) $this->account_id)->exists()) {
            $this->addError('account_id', 'That account is not one you can work with.');

            return;
        }

        $data = TicketData::fromArray([
            'subject' => $this->subject,
            'description' => $this->description,
            'priority' => $this->priority,
            'source' => $this->source,
            'contact_id' => $this->contact_id,
            'account_id' => $this->account_id,
            'owner_id' => $this->ownerIdFor($ticket),
        ]);

        try {
            $saved = $ticket === null
                ? app(CreateTicketAction::class)($data, $this->currentUser())
                : app(UpdateTicketAction::class)($ticket, $data);
        } catch (RuntimeException $exception) {
            $this->addError('subject', $exception->getMessage());

            return;
        }

        $saved->saveCustomFields($this->customFields);

        session()->flash('status', $saved->reference().' was saved.');

        $this->redirectRoute('tickets.show', ['ticket' => $saved->id], navigate: true);
    }

    /**
     * Who the ticket ends up with.
     *
     * Somebody who may not assign never gets to choose: the agent they
     * submitted is dropped here rather than merely hidden in the view, so the
     * ticket stays with whoever has it — or, for a new one, with them.
     */
    private function ownerIdFor(?Ticket $ticket): int
    {
        if ($this->canAssign($ticket)) {
            return (int) $this->owner_id;
        }

        return $ticket === null ? (int) auth()->id() : $ticket->owner_id;
    }

    /**
     * Whether this person may choose the agent. Without it the field is not
     * rendered and a submitted agent is ignored server-side.
     */
    public function canAssign(?Ticket $ticket = null): bool
    {
        $ticket ??= $this->ticket();

        return $ticket === null
            ? auth()->user()?->can('create', Ticket::class) === true
            : auth()->user()?->can('assign', $ticket) === true;
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
     * People, narrowed to the chosen account when there is one.
     *
     * Not narrowed when there is not: plenty of tickets come from somebody
     * whose organisation nobody has recorded yet, and refusing to offer them
     * would mean typing the account first to raise a ticket at all.
     *
     * @return array<string, mixed>
     */
    public function searchContacts(?string $term = null, mixed $page = 1): array
    {
        $term = (string) ($term ?? '');
        $page = max(1, (int) $page);

        $rows = Contact::query()
            ->visibleTo(auth()->user())
            ->when(
                $this->account_id !== null && $this->account_id !== '',
                fn ($query) => $query->where('account_id', (int) $this->account_id)
            )
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
     * Int-keyed, not string-keyed: see TicketPriority::options().
     *
     * @return array<int, string>
     */
    public function priorityOptions(): array
    {
        return TicketPriority::options();
    }

    /**
     * @return array<string, string>
     */
    public function sourceOptions(): array
    {
        return TicketSource::options();
    }

    /**
     * PHP casts a numeric string array key back to int, so the id keys here are
     * ints however they are written — typed as such rather than pretending.
     *
     * @return array<int, string>
     */
    public function agentOptions(): array
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
        return view('livewire.support.ticket-form')
            ->title($this->isEditing() ? 'Edit ticket' : 'New ticket');
    }
}
