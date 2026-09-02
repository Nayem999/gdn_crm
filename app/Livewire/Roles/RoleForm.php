<?php

namespace App\Livewire\Roles;

use App\Domain\Access\Actions\CreateRoleAction;
use App\Domain\Access\Actions\UpdateRoleAction;
use App\Domain\Access\DTOs\RoleData;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Shared\Enums\DataAccessLevel;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Component;
use RuntimeException;
use Spatie\Permission\Models\Role;

class RoleForm extends Component
{
    use AuthorizesRequests;

    public ?Role $role = null;

    public string $name = '';

    public string $dataAccessLevel = DataAccessLevel::Own->value;

    /** @var array<int, string> */
    public array $permissions = [];

    public function mount(?Role $role = null): void
    {
        // An unsaved model instance means this is the create route.
        $this->role = $role?->exists ? $role : null;

        if ($this->role !== null) {
            $this->authorize('update', $this->role);

            $this->name = $this->role->name;
            $this->dataAccessLevel = (string) ($this->role->getAttribute('data_access_level') ?? DataAccessLevel::Own->value);
            $this->permissions = $this->role->permissions->pluck('name')->all();

            return;
        }

        $this->authorize('create', Role::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('roles', 'name')->ignore($this->role?->id),
            ],
            'dataAccessLevel' => ['required', Rule::enum(DataAccessLevel::class)],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalogue::all())],
        ];
    }

    /**
     * Tick or clear every permission in one module group.
     */
    public function toggleGroup(string $group): void
    {
        $groupPermissions = array_keys(PermissionCatalogue::groups()[$group]['permissions'] ?? []);

        if ($groupPermissions === []) {
            return;
        }

        $allSelected = $this->groupIsFullySelected($group);

        $this->permissions = $allSelected
            ? array_values(array_diff($this->permissions, $groupPermissions))
            : array_values(array_unique([...$this->permissions, ...$groupPermissions]));
    }

    public function groupIsFullySelected(string $group): bool
    {
        $groupPermissions = array_keys(PermissionCatalogue::groups()[$group]['permissions'] ?? []);

        return $groupPermissions !== [] && array_diff($groupPermissions, $this->permissions) === [];
    }

    public function selectedCountFor(string $group): int
    {
        $groupPermissions = array_keys(PermissionCatalogue::groups()[$group]['permissions'] ?? []);

        return count(array_intersect($groupPermissions, $this->permissions));
    }

    public function save(CreateRoleAction $createRole, UpdateRoleAction $updateRole): void
    {
        $validated = $this->validate();

        $data = new RoleData(
            name: $validated['name'],
            dataAccessLevel: DataAccessLevel::from($validated['dataAccessLevel']),
            permissions: $validated['permissions'] ?? [],
        );

        if ($this->role !== null) {
            $this->authorize('update', $this->role);

            try {
                $updateRole($this->role, $data);
            } catch (RuntimeException $exception) {
                $this->addError('name', $exception->getMessage());

                return;
            }
        } else {
            $this->authorize('create', Role::class);
            $createRole($data);
        }

        session()->flash('status', $this->role !== null ? 'Role updated.' : 'Role created.');

        $this->redirectRoute('settings.roles', navigate: true);
    }

    /**
     * @return array<string, array{label: string, icon: string, permissions: array<string, string>}>
     */
    public function groups(): array
    {
        return PermissionCatalogue::groups();
    }

    public function selectedLevel(): DataAccessLevel
    {
        return DataAccessLevel::tryFrom($this->dataAccessLevel) ?? DataAccessLevel::Own;
    }

    public function render(): View
    {
        return view('livewire.roles.role-form')
            ->title($this->role !== null ? 'Edit role' : 'Add role');
    }
}
