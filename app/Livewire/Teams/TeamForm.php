<?php

namespace App\Livewire\Teams;

use App\Domain\Teams\Actions\CreateTeamAction;
use App\Domain\Teams\Actions\UpdateTeamAction;
use App\Domain\Teams\DTOs\TeamData;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Component;
use RuntimeException;

class TeamForm extends Component
{
    use AuthorizesRequests;

    public ?Team $team = null;

    public string $name = '';

    public string $description = '';

    public ?string $parentId = null;

    /** @var array<int, string> */
    public array $memberIds = [];

    public function mount(?Team $team = null): void
    {
        // An unsaved model instance means this is the create route.
        $this->team = $team?->exists ? $team : null;

        if ($this->team !== null) {
            $this->authorize('update', $this->team);

            $this->name = $this->team->name;
            $this->description = (string) $this->team->description;
            $this->parentId = $this->team->parent_id !== null ? (string) $this->team->parent_id : null;
            $this->memberIds = $this->team->users()->pluck('users.id')->map(fn ($id) => (string) $id)->all();

            return;
        }

        $this->authorize('create', Team::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('teams', 'name')->ignore($this->team?->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'parentId' => ['nullable', 'exists:teams,id'],
            'memberIds' => ['array'],
            'memberIds.*' => ['exists:users,id'],
        ];
    }

    public function save(CreateTeamAction $createTeam, UpdateTeamAction $updateTeam): void
    {
        $validated = $this->validate();

        $data = new TeamData(
            name: $validated['name'],
            description: filled($validated['description']) ? $validated['description'] : null,
            parentId: filled($validated['parentId']) ? (int) $validated['parentId'] : null,
            memberIds: array_map('intval', $validated['memberIds'] ?? []),
        );

        if ($this->team !== null) {
            $this->authorize('update', $this->team);

            try {
                $updateTeam($this->team, $data);
            } catch (RuntimeException $exception) {
                $this->addError('parentId', $exception->getMessage());

                return;
            }
        } else {
            $this->authorize('create', Team::class);
            $createTeam($data);
        }

        session()->flash('status', $this->team !== null ? 'Team updated.' : 'Team created.');

        $this->redirectRoute('settings.teams', navigate: true);
    }

    /**
     * Candidate parents, excluding this team and its own descendants so the UI
     * can't offer a selection that would loop the tree.
     *
     * @return array<string, string>
     */
    public function parentOptions(): array
    {
        $excludedIds = [];

        if ($this->team !== null) {
            $excludedIds = $this->team->descendants()->pluck('id')->push($this->team->id)->all();
        }

        return Team::query()
            ->when($excludedIds !== [], fn ($query) => $query->whereNotIn('id', $excludedIds))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function memberOptions(): array
    {
        return User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(fn (User $user) => [$user->id => $user->name.' ('.$user->email.')'])
            ->all();
    }

    public function render(): View
    {
        return view('livewire.teams.team-form')
            ->title($this->team !== null ? 'Edit team' : 'Add team');
    }
}
