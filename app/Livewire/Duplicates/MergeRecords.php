<?php

namespace App\Livewire\Duplicates;

use App\Domain\Shared\Actions\MergeRecordsAction;
use App\Domain\Shared\Duplicates\DuplicateFinder;
use App\Domain\Shared\Duplicates\DuplicateMatch;
use App\Domain\Shared\Duplicates\DuplicateRegistry;
use App\Domain\Shared\Duplicates\DuplicateSource;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * One screen that merges duplicates for every module that declares itself in
 * DuplicateRegistry.
 *
 * The module arrives in the URL, so it is resolved through the registry and
 * nothing else: an unlisted module 404s rather than being turned into a class
 * name. Every record is then loaded through the module's own visible query and
 * authorised, so a guessed id reaches nothing.
 */
#[Title('Merge duplicates')]
class MergeRecords extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public string $module;

    #[Locked]
    public int $recordId;

    /**
     * The counterpart being merged with, chosen from the candidate list.
     */
    #[Url(as: 'with', except: null)]
    public ?int $otherId = null;

    /**
     * Which of the two records survives.
     */
    public string $keep = 'this';

    /**
     * Field => 'this' | 'other'. Only fields the module declares mergeable are
     * ever read from here.
     *
     * @var array<string, string>
     */
    public array $chosen = [];

    public function mount(string $module, int $record): void
    {
        if (! DuplicateRegistry::has($module)) {
            abort(404);
        }

        $this->module = $module;
        $this->recordId = $record;

        $this->authorize('merge', $this->record());

        $this->resetChoices();
    }

    // -- The two records ------------------------------------------------------

    public function source(): DuplicateSource
    {
        $source = DuplicateRegistry::find($this->module);

        if ($source === null) {
            abort(404);
        }

        return $source;
    }

    public function record(): Model
    {
        return $this->source()
            ->visibleQuery($this->currentUser())
            ->whereKey($this->recordId)
            ->firstOr(fn () => abort(404));
    }

    public function other(): ?Model
    {
        if ($this->otherId === null) {
            return null;
        }

        return $this->source()
            ->visibleQuery($this->currentUser())
            ->whereKey($this->otherId)
            ->whereNull('merged_into_id')
            ->first();
    }

    public function survivor(): Model
    {
        return $this->keep === 'other' ? ($this->other() ?? $this->record()) : $this->record();
    }

    public function loser(): ?Model
    {
        $other = $this->other();

        if ($other === null) {
            return null;
        }

        return $this->keep === 'other' ? $this->record() : $other;
    }

    /**
     * @return array<int, DuplicateMatch>
     */
    public function candidates(): array
    {
        return app(DuplicateFinder::class)->for($this->source(), $this->record(), $this->currentUser());
    }

    // -- Choosing -------------------------------------------------------------

    public function selectCounterpart(int $id): void
    {
        // Only an id the finder actually offered, so the page cannot be pointed
        // at an arbitrary record by editing the query string.
        $offered = array_map(
            fn (DuplicateMatch $match) => (int) $match->record->getKey(),
            $this->candidates()
        );

        $this->otherId = in_array($id, $offered, true) ? $id : null;
        $this->resetChoices();
    }

    public function updatedOtherId(): void
    {
        $this->selectCounterpart((int) $this->otherId);
    }

    public function updatedKeep(): void
    {
        $this->keep = $this->keep === 'other' ? 'other' : 'this';
        $this->resetChoices();
    }

    public function chooseField(string $field, string $side): void
    {
        if (! array_key_exists($field, $this->source()->mergeableFields())) {
            return;
        }

        $this->chosen[$field] = $side === 'other' ? 'other' : 'this';
    }

    public function takeAllFrom(string $side): void
    {
        foreach (array_keys($this->source()->mergeableFields()) as $field) {
            $this->chosen[$field] = $side === 'other' ? 'other' : 'this';
        }
    }

    /**
     * The starting point: keep what the survivor has, and fill its blanks from
     * the other record rather than discarding information for no reason.
     */
    public function resetChoices(): void
    {
        $this->chosen = [];

        $survivor = $this->survivor();
        $loser = $this->loser();

        if ($loser === null) {
            return;
        }

        [$thisSide, $otherSide] = $this->keep === 'other' ? ['other', 'this'] : ['this', 'other'];

        foreach (array_keys($this->source()->mergeableFields()) as $field) {
            $survivorBlank = $this->isBlank($survivor->getAttribute($field));
            $loserHasValue = ! $this->isBlank($loser->getAttribute($field));

            $this->chosen[$field] = $survivorBlank && $loserHasValue ? $otherSide : $thisSide;
        }
    }

    // -- Merging --------------------------------------------------------------

    public function merge(): void
    {
        $source = $this->source();
        $survivor = $this->survivor();
        $loser = $this->loser();

        if ($loser === null) {
            $this->dispatch('notify', type: 'error', message: 'Choose which record this is a duplicate of.');

            return;
        }

        $this->authorize('merge', $survivor);
        $this->authorize('merge', $loser);

        try {
            app(MergeRecordsAction::class)($source, $survivor, $loser, $this->chosenValues());
        } catch (RuntimeException $exception) {
            $this->dispatch('notify', type: 'error', message: $exception->getMessage());

            return;
        }

        session()->flash('status', $source->label($loser).' was merged into '.$source->label($survivor).'.');

        $this->redirect($source->showRoute($survivor), navigate: true);
    }

    /**
     * The values the merge should write: the loser's, for the fields where the
     * operator asked for them.
     *
     * @return array<string, mixed>
     */
    public function chosenValues(): array
    {
        $survivor = $this->survivor();
        $loser = $this->loser();

        if ($loser === null) {
            return [];
        }

        $keepSide = $this->keep === 'other' ? 'other' : 'this';
        $values = [];

        foreach (array_keys($this->source()->mergeableFields()) as $field) {
            $picked = $this->chosen[$field] ?? $keepSide;

            // Nothing to write when the survivor already holds the choice.
            if ($picked === $keepSide) {
                continue;
            }

            $values[$field] = $loser->getAttribute($field);
        }

        return $values;
    }

    // -- Display --------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    public function fields(): array
    {
        return $this->source()->mergeableFields();
    }

    public function valueFor(?Model $record, string $field): string
    {
        return $record === null ? '—' : $this->source()->displayValue($record, $field);
    }

    /**
     * Fields where the two records disagree, which are the only ones anybody
     * has to think about.
     *
     * @return array<int, string>
     */
    public function conflictingFields(): array
    {
        $loser = $this->loser();

        if ($loser === null) {
            return [];
        }

        $survivor = $this->survivor();

        return array_values(array_filter(
            array_keys($this->fields()),
            fn (string $field) => $this->valueFor($survivor, $field) !== $this->valueFor($loser, $field)
        ));
    }

    public function render(): View
    {
        return view('livewire.duplicates.merge-records');
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private function currentUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
