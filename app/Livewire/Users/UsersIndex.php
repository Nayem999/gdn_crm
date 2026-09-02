<?php

namespace App\Livewire\Users;

use App\Domain\Users\Actions\DeleteUserAction;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

#[Title('Users')]
class UsersIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    /** Reflected in the query string so a filtered list is shareable. */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 25)]
    public int $perPage = 25;

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    public function delete(int $userId, DeleteUserAction $action): void
    {
        $user = User::query()->findOrFail($userId);

        $this->authorize('delete', $user);

        try {
            $action($user, auth()->user());
        } catch (RuntimeException $exception) {
            $this->addError('delete', $exception->getMessage());

            return;
        }

        $this->dispatch('user-deleted', name: $user->name);
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function users(): LengthAwarePaginator
    {
        return User::query()
            ->with(['roles', 'currentTeam'])
            ->when($this->search !== '', function ($query) {
                $term = '%'.$this->search.'%';

                $query->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)->orWhere('email', 'like', $term);
                });
            })
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    public function render(): View
    {
        return view('livewire.users.users-index', ['users' => $this->users()]);
    }
}
