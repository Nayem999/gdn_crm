<?php

namespace App\Livewire\Accounts;

use App\Domain\Accounts\AccountDuplicates;
use App\Domain\Accounts\Actions\DeleteAccountAction;
use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Shared\Concerns\FindsDuplicates;
use App\Domain\Shared\Duplicates\DuplicateSource;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One account's profile and its place in the hierarchy.
 *
 * Task 2.8 hangs the record timeline off this page.
 */
class AccountShow extends Component
{
    use AuthorizesRequests;
    use FindsDuplicates;

    #[Locked]
    public int $accountId;

    public function mount(Account $account): void
    {
        $this->authorize('view', $account);

        $this->accountId = $account->id;
    }

    /**
     * Trashed records are included so a merged one stays readable: keeping it
     * is what makes its history survive, and a page nobody can open is not
     * kept in any useful sense. An ordinary deletion is still gone.
     */
    public function account(): Account
    {
        $account = Account::withTrashed()
            ->with(['owner', 'parent'])
            ->findOrFail($this->accountId);

        abort_if($account->trashed() && ! $account->isMerged(), 404);

        return $account;
    }

    /**
     * The chain from the root down to this account, for the breadcrumb.
     *
     * @return Collection<int, Account>
     */
    public function lineage(): Collection
    {
        return $this->account()->ancestors()->reverse()->values();
    }

    /**
     * Direct subsidiaries the viewer is allowed to see.
     *
     * Scoped: a child owned by someone outside the viewer's access level must
     * not become visible just because its parent is.
     *
     * @return Collection<int, Account>
     */
    public function children(): Collection
    {
        return Account::query()
            ->visibleTo(auth()->user())
            ->where('parent_id', $this->accountId)
            ->with('owner:id,name')
            ->orderBy('name')
            ->get();
    }

    /**
     * The people at this account, primary first.
     *
     * Scoped: a contact owned by someone outside the viewer's access level must
     * not become visible just because their account is.
     *
     * @return Collection<int, Contact>
     */
    public function contacts(): Collection
    {
        return Contact::query()
            ->visibleTo(auth()->user())
            ->where('account_id', $this->accountId)
            ->with('owner:id,name')
            ->orderByDesc('is_primary')
            ->orderBy('last_name')
            ->get();
    }

    public function delete(): void
    {
        $account = $this->account();

        $this->authorize('delete', $account);

        app(DeleteAccountAction::class)($account);

        session()->flash('status', $account->name.' was removed.');

        $this->redirectRoute('accounts.index', navigate: true);
    }

    public function duplicateSource(): ?DuplicateSource
    {
        return app(AccountDuplicates::class);
    }

    public function render(): View
    {
        $account = $this->account();

        return view('livewire.accounts.account-show', [
            'account' => $account,
            'lineage' => $this->lineage(),
            'children' => $this->children(),
            'contacts' => $this->contacts(),
            'duplicates' => $this->duplicatesOf($account),
        ])->title($account->name);
    }
}
