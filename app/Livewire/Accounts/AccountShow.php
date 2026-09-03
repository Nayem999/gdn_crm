<?php

namespace App\Livewire\Accounts;

use App\Domain\Accounts\Actions\DeleteAccountAction;
use App\Domain\Accounts\Models\Account;
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

    #[Locked]
    public int $accountId;

    public function mount(Account $account): void
    {
        $this->authorize('view', $account);

        $this->accountId = $account->id;
    }

    public function account(): Account
    {
        return Account::query()
            ->with(['owner', 'parent'])
            ->findOrFail($this->accountId);
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

    public function delete(): void
    {
        $account = $this->account();

        $this->authorize('delete', $account);

        app(DeleteAccountAction::class)($account);

        session()->flash('status', $account->name.' was removed.');

        $this->redirectRoute('accounts.index', navigate: true);
    }

    public function render(): View
    {
        $account = $this->account();

        return view('livewire.accounts.account-show', [
            'account' => $account,
            'lineage' => $this->lineage(),
            'children' => $this->children(),
        ])->title($account->name);
    }
}
