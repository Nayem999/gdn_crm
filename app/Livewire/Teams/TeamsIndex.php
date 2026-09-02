<?php

namespace App\Livewire\Teams;

use App\Domain\Teams\Actions\DeleteTeamAction;
use App\Models\Team;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Teams')]
class TeamsIndex extends Component
{
    use AuthorizesRequests;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Team::class);
    }

    public function clearSearch(): void
    {
        $this->search = '';
    }

    public function delete(int $teamId, DeleteTeamAction $action): void
    {
        $team = Team::query()->findOrFail($teamId);

        $this->authorize('delete', $team);

        $action($team);

        $this->dispatch('team-deleted', name: $team->name);
    }

    /**
     * Teams flattened depth-first so the hierarchy reads top-down, each row
     * carrying its depth for indentation.
     *
     * @return Collection<int, array{team: Team, depth: int}>
     */
    public function teamRows(): Collection
    {
        $teams = Team::query()
            ->with(['users:id', 'children'])
            ->withCount(['users', 'activeMembers'])
            ->orderBy('name')
            ->get();

        // A search flattens the tree: matches are shown on their own rather than
        // hiding under parents that don't match.
        if ($this->search !== '') {
            $term = mb_strtolower($this->search);

            return $teams
                ->filter(fn (Team $team) => str_contains(mb_strtolower($team->name), $term)
                    || str_contains(mb_strtolower((string) $team->description), $term))
                ->map(fn (Team $team) => $this->row($team, 0))
                ->values();
        }

        /** @var array<int, list<Team>> $byParent */
        $byParent = [];

        // Root teams collect under 0, which no real team id can occupy.
        foreach ($teams as $team) {
            $byParent[(int) $team->parent_id][] = $team;
        }

        return $this->flatten($byParent, 0, 0);
    }

    /**
     * @param  array<int, list<Team>>  $byParent
     * @return Collection<int, array{team: Team, depth: int}>
     */
    private function flatten(array $byParent, int $parentId, int $depth): Collection
    {
        $rows = new Collection;

        foreach ($byParent[$parentId] ?? [] as $team) {
            $rows->push($this->row($team, $depth));
            $rows = $rows->merge($this->flatten($byParent, $team->id, $depth + 1));
        }

        return $rows;
    }

    /**
     * @return array{team: Team, depth: int}
     */
    private function row(Team $team, int $depth): array
    {
        return ['team' => $team, 'depth' => $depth];
    }

    public function render(): View
    {
        return view('livewire.teams.teams-index', ['rows' => $this->teamRows()]);
    }
}
