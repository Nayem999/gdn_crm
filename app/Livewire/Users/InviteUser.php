<?php

namespace App\Livewire\Users;

use App\Domain\Users\Actions\InviteUserAction;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role;

#[Title('Invite user')]
class InviteUser extends Component
{
    use AuthorizesRequests;

    public string $email = '';

    public string $name = '';

    public ?string $roleId = null;

    public ?string $teamId = null;

    public function mount(): void
    {
        $this->authorize('invite', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                // An address that already has an account can't be invited again.
                Rule::unique('users', 'email')->withoutTrashed(),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'roleId' => ['nullable', 'exists:roles,id'],
            'teamId' => ['nullable', 'exists:teams,id'],
        ];
    }

    public function invite(InviteUserAction $action): void
    {
        $this->authorize('invite', User::class);

        $validated = $this->validate();

        $action(
            email: $validated['email'],
            name: filled($validated['name']) ? $validated['name'] : null,
            roleId: filled($validated['roleId']) ? (int) $validated['roleId'] : null,
            teamId: filled($validated['teamId']) ? (int) $validated['teamId'] : null,
            invitedBy: auth()->user(),
        );

        session()->flash('status', 'Invitation sent to '.$validated['email'].'.');

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
        return view('livewire.users.invite-user');
    }
}
