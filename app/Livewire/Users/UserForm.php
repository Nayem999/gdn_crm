<?php

namespace App\Livewire\Users;

use App\Domain\Users\Actions\CreateUserAction;
use App\Domain\Users\Actions\UpdateUserAction;
use App\Domain\Users\DTOs\UserData;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Spatie\Permission\Models\Role;

class UserForm extends Component
{
    use AuthorizesRequests, WithFileUploads;

    public ?User $user = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?string $roleId = null;

    public ?string $currentTeamId = null;

    /** @var TemporaryUploadedFile|null */
    public $avatar = null;

    public function mount(?User $user = null): void
    {
        // An unsaved model instance means this is the create route.
        $this->user = $user?->exists ? $user : null;

        if ($this->user !== null) {
            $this->authorize('update', $this->user);

            $firstRole = $this->user->roles->first();

            $this->name = $this->user->name;
            $this->email = $this->user->email;
            // The selects bind to strings, so ids are cast for the round trip.
            $this->roleId = $firstRole !== null ? (string) $firstRole->getKey() : null;
            $this->currentTeamId = $this->user->current_team_id !== null
                ? (string) $this->user->current_team_id
                : null;

            return;
        }

        $this->authorize('create', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->user?->id)->withoutTrashed(),
            ],
            // Optional on edit: leaving it blank keeps the existing password.
            'password' => [$this->user === null ? 'nullable' : 'nullable', 'string', 'min:8', 'confirmed'],
            'roleId' => ['nullable', 'exists:roles,id'],
            'currentTeamId' => ['nullable', 'exists:teams,id'],
            'avatar' => ['nullable', 'image', 'max:2048'],
        ];
    }

    public function save(CreateUserAction $createUser, UpdateUserAction $updateUser): void
    {
        $validated = $this->validate();

        $data = new UserData(
            name: $validated['name'],
            email: $validated['email'],
            password: filled($validated['password']) ? $validated['password'] : null,
            roleId: filled($validated['roleId']) ? (int) $validated['roleId'] : null,
            currentTeamId: filled($validated['currentTeamId']) ? (int) $validated['currentTeamId'] : null,
        );

        if ($this->user !== null) {
            $this->authorize('update', $this->user);
            $updateUser($this->user, $data, $this->avatar);
        } else {
            $this->authorize('create', User::class);
            $createUser($data, $this->avatar);
        }

        session()->flash('status', $this->user !== null ? 'User updated.' : 'User created.');

        $this->redirectRoute('settings.users', navigate: true);
    }

    /**
     * @return array<string, string>
     */
    public function roleOptions(): array
    {
        return Role::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, string>
     */
    public function teamOptions(): array
    {
        return Team::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function render(): View
    {
        return view('livewire.users.user-form')
            ->title($this->user !== null ? 'Edit user' : 'Add user');
    }
}
