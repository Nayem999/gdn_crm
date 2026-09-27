<?php

namespace App\Domain\Leads\Concerns;

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The organisation and person pickers on the lead form and the convert page.
 *
 * Searched on the server across everything the viewer can see — not only the
 * records that happen to look like the lead — with those look-alikes offered
 * first. "Create a new one" is the empty state of the dropdown, not an option:
 * Tom Select drops an option whose value is empty, which left the list blank
 * whenever nothing on file looked like the lead.
 *
 * `exists` would prove a chosen record is real, never that this person may
 * attach a lead to it, so a chosen id is always read back through visibleTo().
 */
trait PicksAccountAndContact
{
    /**
     * Rows per page of a picker's server-side results.
     */
    protected int $pickerPageSize = 25;

    /**
     * Accounts that look like this lead, shown before anything is typed.
     *
     * @return array<int, int>
     */
    protected function suggestedAccountIds(): array
    {
        return [];
    }

    /**
     * @return array<int, int>
     */
    protected function suggestedContactIds(): array
    {
        return [];
    }

    /**
     * @return array{options: array<int, array{value: string, label: string, description: ?string}>, hasMore: bool}
     */
    public function searchAccounts(?string $term = null, mixed $page = 1): array
    {
        return $this->searchPicker(
            Account::query()->visibleTo($this->pickerViewer())->search((string) $term)->orderBy('name'),
            (string) ($term ?? ''),
            max(1, (int) $page),
            $this->suggestedAccountIds(),
            fn (Account $account): array => $this->accountRow($account),
        );
    }

    /**
     * @return array{options: array<int, array{value: string, label: string, description: ?string}>, hasMore: bool}
     */
    public function searchContacts(?string $term = null, mixed $page = 1): array
    {
        return $this->searchPicker(
            Contact::query()->visibleTo($this->pickerViewer())->search((string) $term)->with('account:id,name')->orderBy('last_name')->orderBy('first_name'),
            (string) ($term ?? ''),
            max(1, (int) $page),
            $this->suggestedContactIds(),
            fn (Contact $contact): array => $this->contactRow($contact),
        );
    }

    protected function visibleAccount(?string $id): ?Account
    {
        if ($id === null || $id === '') {
            return null;
        }

        return Account::query()->visibleTo($this->pickerViewer())->whereKey((int) $id)->first();
    }

    protected function visibleContact(?string $id): ?Contact
    {
        if ($id === null || $id === '') {
            return null;
        }

        return Contact::query()->visibleTo($this->pickerViewer())->with('account:id,name')->whereKey((int) $id)->first();
    }

    /**
     * The options a picker starts with: whatever is chosen, then the
     * look-alikes, so they are there before anything is typed.
     *
     * @param  array<int, int>  $suggested
     * @return array<int, array{value: string, label: string, description: ?string}>
     */
    protected function accountPickerOptions(?string $selected, array $suggested = []): array
    {
        $ids = array_values(array_unique(array_filter([...($selected ? [(int) $selected] : []), ...$suggested])));

        return Account::query()->visibleTo($this->pickerViewer())->whereKey($ids)->get()
            ->sortBy(fn (Account $account) => array_search($account->id, $ids, true))
            ->map(fn (Account $account): array => $this->accountRow($account))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $suggested
     * @return array<int, array{value: string, label: string, description: ?string}>
     */
    protected function contactPickerOptions(?string $selected, array $suggested = []): array
    {
        $ids = array_values(array_unique(array_filter([...($selected ? [(int) $selected] : []), ...$suggested])));

        return Contact::query()->visibleTo($this->pickerViewer())->with('account:id,name')->whereKey($ids)->get()
            ->sortBy(fn (Contact $contact) => array_search($contact->id, $ids, true))
            ->map(fn (Contact $contact): array => $this->contactRow($contact))
            ->values()
            ->all();
    }

    /**
     * @return array{value: string, label: string, description: ?string}
     */
    private function accountRow(Account $account): array
    {
        return ['value' => (string) $account->id, 'label' => $account->name, 'description' => $account->city];
    }

    /**
     * @return array{value: string, label: string, description: ?string}
     */
    private function contactRow(Contact $contact): array
    {
        return [
            'value' => (string) $contact->id,
            'label' => $contact->fullName(),
            'description' => $contact->account->name ?? $contact->email,
        ];
    }

    /**
     * One page of a picker, with the suggestions leading page one when nothing
     * has been typed yet.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  already narrowed by the search term
     * @param  array<int, int>  $suggested
     * @param  callable(TModel): array{value: string, label: string, description: ?string}  $row
     * @return array{options: array<int, array{value: string, label: string, description: ?string}>, hasMore: bool}
     */
    private function searchPicker(Builder $query, string $term, int $page, array $suggested, callable $row): array
    {
        $leading = [];

        if ($term === '' && $suggested !== []) {
            if ($page === 1) {
                $leading = (clone $query)->whereKey($suggested)->get()->map($row)->values()->all();
            }

            $query->whereKeyNot($suggested);
        }

        $rows = $query
            ->limit($this->pickerPageSize + 1)
            ->offset(($page - 1) * $this->pickerPageSize)
            ->get();

        return [
            'options' => [...$leading, ...$rows->take($this->pickerPageSize)->map($row)->values()->all()],
            'hasMore' => $rows->count() > $this->pickerPageSize,
        ];
    }

    private function pickerViewer(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
