<?php

namespace App\Livewire\Roles;

use App\Domain\Access\Actions\DeleteRoleAction;
use App\Domain\Access\Policies\RolePolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;
use Spatie\Permission\Models\Role;

#[Title('Roles & permissions')]
class RolesIndex extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('viewAny', Role::class);
    }

    public function delete(int $roleId, DeleteRoleAction $action): void
    {
        $role = Role::query()->findOrFail($roleId);

        $this->authorize('delete', $role);

        try {
            $action($role);
        } catch (RuntimeException $exception) {
            $this->addError('delete', $exception->getMessage());

            return;
        }

        $this->dispatch('role-deleted', name: $role->name);
    }

    /**
     * @return Collection<int, Role>
     */
    public function roles(): Collection
    {
        return Role::query()
            ->withCount(['permissions', 'users'])
            ->orderBy('name')
            ->get();
    }

    public function isProtected(Role $role): bool
    {
        return app(RolePolicy::class)->isProtected($role);
    }

    public function render(): View
    {
        return view('livewire.roles.roles-index', ['roles' => $this->roles()]);
    }
}
