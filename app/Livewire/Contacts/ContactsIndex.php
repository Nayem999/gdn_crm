<?php

namespace App\Livewire\Contacts;

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Actions\DeleteContactAction;
use App\Domain\Contacts\ContactExportSource;
use App\Domain\Contacts\ContactFields;
use App\Domain\Contacts\Enums\Department;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Shared\Concerns\ExportsDataView;
use App\Domain\Shared\Concerns\WithDataView;
use App\Domain\Shared\DataView\Column;
use App\Domain\Shared\Exports\DataViewExportSource;
use App\Domain\Shared\Filters\FilterField;
use App\Domain\Shared\UI\ChipPalette;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The contacts list, on the shared data-view kit.
 *
 * The component owns the query — including visibleTo() — so a record outside the
 * viewer's access level never reaches the page, whichever view they are in.
 */
#[Title('Contacts')]
class ContactsIndex extends Component
{
    use AuthorizesRequests;
    use ExportsDataView;

    // Aliased so cellFor() can hand anything it does not style back to the kit.
    use WithDataView {
        cellFor as defaultCellFor;
    }

    #[Url(as: 'chip', except: '')]
    public string $quickFilter = '';

    /**
     * Set when the list is opened from one account's page.
     */
    #[Url(as: 'account', except: null)]
    public ?int $accountId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Contact::class);

        $this->mountWithDataView();
    }

    // -- Data view contract --------------------------------------------------

    public function dataViewModule(): string
    {
        return 'contacts';
    }

    /**
     * @return array<int, Column>
     */
    public function dataViewColumns(): array
    {
        return ContactFields::columns();
    }

    /**
     * @return Builder<Contact>
     */
    public function dataViewBaseQuery(): Builder
    {
        $query = Contact::query()
            ->visibleTo(auth()->user())
            ->with(['account:id,name', 'owner:id,name']);

        if ($this->accountId !== null) {
            $query->where('contacts.account_id', $this->accountId);
        }

        return match ($this->quickFilter) {
            'mine' => $query->where('contacts.owner_id', auth()->id()),
            'primary' => $query->where('contacts.is_primary', true),
            'unlinked' => $query->whereNull('contacts.account_id'),
            'this_week' => $query->where('contacts.created_at', '>=', now()->startOfWeek()),
            default => $query,
        };
    }

    /**
     * @return array<int, FilterField>
     */
    public function dataViewFilterFields(): array
    {
        return array_values(ContactFields::filters());
    }

    /**
     * @return array<int, string>
     */
    public function dataViewSearchColumns(): array
    {
        return ContactFields::searchColumns();
    }

    /**
     * Grouped by department: ten columns, and dragging someone between them is
     * a change a salesperson would actually make. There is no status-like field
     * on a contact to group by instead.
     */
    public function dataViewKanbanField(): ?string
    {
        return 'department';
    }

    /**
     * @return array<int, array{value: string, label: string, color: string|null}>
     */
    public function dataViewKanbanColumns(): array
    {
        $board = [];

        foreach (Department::cases() as $department) {
            $board[] = [
                'value' => $department->value,
                'label' => $department->label(),
                'color' => $department->color(),
            ];
        }

        return $board;
    }

    public function dataViewExportSource(): ?DataViewExportSource
    {
        return auth()->user()?->can('contacts.export') === true
            ? app(ContactExportSource::class)
            : null;
    }

    public function cellFor(Model $record, Column $column): string|HtmlString
    {
        /** @var Contact $record */
        return match ($column->key) {
            'name' => new HtmlString(
                '<a href="'.e(route('contacts.show', $record)).'" wire:navigate '
                .'class="font-medium text-foreground hover:text-accent hover:underline">'
                .e($record->fullName()).'</a>'
            ),
            'account' => $record->account === null
                ? $this->blank()
                : new HtmlString(
                    '<a href="'.e(route('accounts.show', $record->account)).'" wire:navigate '
                    .'class="text-muted-foreground hover:text-accent hover:underline">'
                    .e($record->account->name).'</a>'
                ),
            'department' => $record->department() === null
                ? $this->blank()
                : new HtmlString(ChipPalette::chip(
                    $record->department()->label(),
                    $record->department()->color()
                )),
            'is_primary' => $record->is_primary
                ? new HtmlString(ChipPalette::chip('Primary', 'emerald'))
                : $this->blank(),
            'email' => $record->email === null
                ? $this->blank()
                : new HtmlString(
                    '<a href="mailto:'.e($record->email).'" class="text-accent hover:underline">'
                    .e($record->email).'</a>'
                ),
            'owner' => $record->owner === null ? $this->blank() : $record->owner->name,
            default => $this->defaultCellFor($record, $column),
        };
    }

    // -- Quick filters -------------------------------------------------------

    public function setQuickFilter(string $chip): void
    {
        $this->quickFilter = in_array($chip, ['mine', 'primary', 'unlinked', 'this_week'], true)
            && $this->quickFilter !== $chip
            ? $chip
            : '';

        $this->resetPage();
    }

    public function clearAllFilters(): void
    {
        $this->quickFilter = '';
        $this->accountId = null;
        $this->clearFilters();
        $this->search = '';
    }

    public function filteredAccountName(): ?string
    {
        if ($this->accountId === null) {
            return null;
        }

        return Account::query()
            ->visibleTo(auth()->user())
            ->whereKey($this->accountId)
            ->value('name');
    }

    // -- Actions -------------------------------------------------------------

    public function delete(int $contactId): void
    {
        $contact = $this->dataViewBaseQuery()->whereKey($contactId)->first();

        if ($contact === null) {
            return;
        }

        $this->authorize('delete', $contact);

        app(DeleteContactAction::class)($contact);

        $this->clearSelection();
        $this->dispatch('contact-deleted', name: $contact->fullName());
    }

    public function deleteSelected(): void
    {
        $this->authorize('create', Contact::class);

        $contacts = $this->dataViewBaseQuery()->whereKey($this->selected)->get();
        $removed = 0;

        foreach ($contacts as $contact) {
            if (auth()->user()?->can('delete', $contact)) {
                app(DeleteContactAction::class)($contact);
                $removed++;
            }
        }

        $this->clearSelection();
        $this->dispatch('contact-deleted', name: $removed.' contacts');
    }

    /**
     * @return LengthAwarePaginator<int, Model>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        return $this->dataViewQuery()->paginate($this->perPage);
    }

    public function render(): View
    {
        return view('livewire.contacts.contacts-index');
    }

    private function blank(): HtmlString
    {
        return new HtmlString('<span class="text-muted-foreground">&mdash;</span>');
    }
}
