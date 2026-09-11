<?php

namespace App\Domain\Shared\Concerns;

use App\Domain\Shared\Enums\ViewMode;
use App\Domain\Shared\Models\SavedView;
use App\Domain\Shared\Models\UserViewPreference;
use App\Domain\Shared\SavedViews\SavedViewState;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Named arrangements of a list screen, saved and reopened.
 *
 * Folded into `WithDataView` rather than added per module, so every list gets
 * them at once and a sixth module cannot land without them.
 *
 * Two rules do the work here:
 *
 * - **Nothing from a saved view is trusted on the way back in.** A view can be
 *   older than the screen it belongs to, so applying one hands every piece
 *   through the same registries that already police what the browser sends: a
 *   removed column stays removed, a retired filter field is dropped, an unknown
 *   view mode falls back. An out-of-date view degrades; it never resurrects.
 * - **A view is addressed by id, and the id is resolved through
 *   `visibleTo()`.** Somebody else's private view is not reachable by guessing
 *   its number.
 */
trait WithSavedViews
{
    /** The saved view currently open, if any. */
    public ?int $savedViewId = null;

    /**
     * The arrangement as it was when the view was opened or last saved.
     *
     * Compared against, rather than the stored blob: restoring normalises —
     * an empty column list becomes the default set, a retired filter is
     * dropped — so a view would otherwise read as "edited" the instant it was
     * opened.
     *
     * @var array<string, mixed>
     */
    public array $savedViewBaseline = [];

    // -- The "save this arrangement" dialog ------------------------------------

    public bool $savingView = false;

    public string $savedViewName = '';

    public bool $savedViewShared = false;

    /**
     * The views this person may open on this module.
     *
     * @return Collection<int, SavedView>
     */
    public function savedViews(): Collection
    {
        $user = $this->currentUser();

        if ($user === null) {
            /** @var Collection<int, SavedView> */
            return new Collection;
        }

        return SavedView::query()
            ->forModule($this->dataViewModule())
            ->visibleTo($user)
            ->with('owner:id,name')
            ->ordered()
            ->get();
    }

    public function currentSavedView(): ?SavedView
    {
        return $this->savedViewId === null ? null : $this->findSavedView($this->savedViewId);
    }

    /**
     * Whether the screen has drifted from the view it was opened on, so the UI
     * can offer to update it rather than silently keeping two truths.
     */
    public function savedViewHasChanges(): bool
    {
        $view = $this->currentSavedView();

        return $view !== null
            && $this->savedViewBaseline !== []
            && $this->savedViewBaseline != $this->captureSavedViewState()->toArray();
    }

    // -- Applying --------------------------------------------------------------

    public function applySavedView(int $savedViewId): void
    {
        $view = $this->findSavedView($savedViewId);

        if ($view === null) {
            return;
        }

        $this->savedViewId = $view->id;
        $this->restoreSavedViewState($view->state());
        $this->rememberSavedViewBaseline();
    }

    public function clearSavedView(): void
    {
        $this->savedViewId = null;
        $this->savedViewBaseline = [];
    }

    /**
     * Take the current arrangement as the thing "edited" is measured against.
     */
    protected function rememberSavedViewBaseline(): void
    {
        $this->savedViewBaseline = $this->captureSavedViewState()->toArray();
    }

    /**
     * Put one saved arrangement back on the screen.
     *
     * Every value goes through the screen's own guards — `setViewMode`,
     * `sanitiseColumns` and the filter field registry — rather than being
     * assigned straight onto the properties.
     */
    protected function restoreSavedViewState(SavedViewState $state): void
    {
        $mode = ViewMode::tryFrom($state->viewMode);

        if ($mode !== null && in_array($mode, $this->availableViewModes(), true)) {
            $this->viewMode = $mode->value;
        }

        $this->search = $state->search;
        $this->sortDirection = $state->sortDirection === 'desc' ? 'desc' : 'asc';

        // A sort on a column the screen no longer offers is dropped rather than
        // left to order by a column that is not in the query.
        $sortable = collect($this->dataViewColumns())->firstWhere('key', $state->sortBy);
        $this->sortBy = $sortable !== null && $sortable->sortable ? $state->sortBy : '';

        $this->perPage = in_array($state->perPage, self::PER_PAGE_OPTIONS, true)
            ? $state->perPage
            : $this->perPage;

        $columns = $this->sanitiseColumns($state->visibleColumns);
        $this->visibleColumns = $columns === [] ? $this->defaultVisibleColumns() : $columns;
        $this->pinnedColumns = $this->sanitiseColumns($state->pinnedColumns);

        $this->filters = $this->sanitiseSavedFilters($state->filters);

        $this->resetPage();
        $this->clearSelection();
    }

    /**
     * The screen's arrangement, as it would be saved.
     */
    public function captureSavedViewState(): SavedViewState
    {
        return new SavedViewState(
            viewMode: $this->viewMode,
            search: $this->search,
            sortBy: $this->sortBy,
            sortDirection: $this->sortDirection,
            perPage: $this->perPage,
            visibleColumns: array_values($this->visibleColumns),
            pinnedColumns: array_values($this->pinnedColumns),
            filters: $this->filters,
        );
    }

    // -- Saving ----------------------------------------------------------------

    public function startSavingView(): void
    {
        $view = $this->currentSavedView();
        $user = $this->currentUser();

        // Editing a view somebody else shared starts a copy rather than
        // offering to overwrite theirs.
        $this->savedViewName = $view !== null && $user !== null && $view->isOwnedBy($user)
            ? $view->name
            : '';
        // `is_shared` is non-nullable on the model, so the nullsafe read would
        // say it could be null for two different reasons when only one applies.
        $this->savedViewShared = $view !== null && $view->is_shared;
        $this->savingView = true;
        $this->resetValidation();
    }

    public function cancelSavingView(): void
    {
        $this->savingView = false;
        $this->savedViewName = '';
        $this->resetValidation();
    }

    public function saveView(): void
    {
        $user = $this->currentUser();

        if ($user === null) {
            return;
        }

        $this->validate([
            'savedViewName' => ['required', 'string', 'min:2', 'max:60'],
        ], [], ['savedViewName' => 'view name']);

        if (! $this->canShareSavedViews()) {
            // Sharing is permission-gated, and a payload cannot grant it.
            $this->savedViewShared = false;
        }

        $existing = SavedView::query()
            ->forModule($this->dataViewModule())
            ->where('owner_id', $user->id)
            ->where('name', $this->savedViewName)
            ->first();

        $view = $existing ?? new SavedView([
            'module' => $this->dataViewModule(),
            'owner_id' => $user->id,
        ]);

        $view->forceFill([
            'module' => $this->dataViewModule(),
            'owner_id' => $user->id,
            'name' => $this->savedViewName,
            'is_shared' => $this->savedViewShared,
            'state' => $this->captureSavedViewState()->toArray(),
        ])->save();

        $this->savedViewId = $view->id;
        $this->savingView = false;
        $this->rememberSavedViewBaseline();

        $this->dispatch('notify', type: 'success', message: $view->name.' saved.');
    }

    /**
     * Update the open view in place, for somebody who owns it.
     */
    public function updateSavedView(): void
    {
        $view = $this->currentSavedView();
        $user = $this->currentUser();

        if ($view === null || $user === null || ! $view->isOwnedBy($user)) {
            return;
        }

        $view->forceFill(['state' => $this->captureSavedViewState()->toArray()])->save();
        $this->rememberSavedViewBaseline();

        $this->dispatch('notify', type: 'success', message: $view->name.' updated.');
    }

    public function deleteSavedView(int $savedViewId): void
    {
        $view = $this->findSavedView($savedViewId);
        $user = $this->currentUser();

        // Only the owner. A shared view is somebody's work, not communal
        // property that anyone who can see it may throw away.
        if ($view === null || $user === null || ! $view->isOwnedBy($user)) {
            return;
        }

        $name = $view->name;

        if ($this->savedViewId === $view->id) {
            $this->savedViewId = null;
            $this->savedViewBaseline = [];
        }

        $view->delete();

        $this->dispatch('notify', type: 'success', message: $name.' was removed.');
    }

    // -- The default view ------------------------------------------------------

    /**
     * Open this module on a given view from now on — for this person only.
     *
     * Per user rather than per view: an owner sharing a view should not be able
     * to change what everybody else's module opens on.
     */
    public function makeSavedViewDefault(?int $savedViewId): void
    {
        $user = $this->currentUser();

        if ($user === null) {
            return;
        }

        $view = $savedViewId === null ? null : $this->findSavedView($savedViewId);

        UserViewPreference::remember($user, $this->dataViewModule(), [
            'default_saved_view_id' => $view?->id,
        ]);

        $this->dispatch(
            'notify',
            type: 'success',
            message: $view === null
                ? 'This module will open on no particular view.'
                : $view->name.' is now how this module opens.',
        );
    }

    public function defaultSavedViewId(): ?int
    {
        $user = $this->currentUser();

        if ($user === null) {
            return null;
        }

        return UserViewPreference::lookup($user, $this->dataViewModule())?->default_saved_view_id;
    }

    /**
     * Open the module on this person's default view, if they have one and it is
     * still there.
     *
     * Called from mount, after the stored layout preference has been read, so a
     * default view wins over the layout somebody happened to leave behind.
     */
    protected function applyDefaultSavedView(): void
    {
        $defaultId = $this->defaultSavedViewId();

        if ($defaultId === null) {
            return;
        }

        $view = $this->findSavedView($defaultId);

        if ($view === null) {
            return;
        }

        $this->savedViewId = $view->id;
        $this->restoreSavedViewState($view->state());
        $this->rememberSavedViewBaseline();
    }

    // -- Permissions -----------------------------------------------------------

    public function canShareSavedViews(): bool
    {
        return $this->currentUser()?->can('saved-views.share') ?? false;
    }

    /**
     * A view by id, but only one this person may open.
     */
    protected function findSavedView(int $savedViewId): ?SavedView
    {
        $user = $this->currentUser();

        if ($user === null) {
            return null;
        }

        return SavedView::query()
            ->forModule($this->dataViewModule())
            ->visibleTo($user)
            ->whereKey($savedViewId)
            ->first();
    }

    /**
     * A stored filter tree, with anything the screen no longer offers removed.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function sanitiseSavedFilters(array $filters): array
    {
        $known = array_keys($this->filterFieldMap());

        return $this->pruneFilterGroup($filters, $known);
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<int, string>  $known
     * @return array<string, mixed>
     */
    private function pruneFilterGroup(array $group, array $known): array
    {
        $conditions = [];

        foreach ($group['conditions'] ?? [] as $condition) {
            if (is_array($condition) && in_array($condition['field'] ?? null, $known, true)) {
                $conditions[] = $condition;
            }
        }

        $groups = [];

        foreach ($group['groups'] ?? [] as $child) {
            if (is_array($child)) {
                $groups[] = $this->pruneFilterGroup($child, $known);
            }
        }

        return [
            'match' => ($group['match'] ?? 'all') === 'any' ? 'any' : 'all',
            'conditions' => $conditions,
            'groups' => $groups,
        ];
    }
}
