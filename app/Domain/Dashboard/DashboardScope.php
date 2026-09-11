<?php

namespace App\Domain\Dashboard;

use App\Domain\Activities\ActivityRelations;
use App\Domain\Activities\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What one person's dashboard is allowed to describe.
 *
 * Both gates, in one place: the **permission** decides whether a widget exists
 * at all, and the module's own `visibleTo()` decides which records it counts. A
 * dashboard is the easiest screen on which to leak a number — a total is still
 * information about records somebody cannot open — so no widget builds its own
 * query from a model class. They ask here.
 *
 * The module list comes from `ActivityRelations` rather than a registry of this
 * module's own. It is the only registry that already pairs a module key with a
 * viewer-scoped query, and a second list of the same four modules is a second
 * thing to forget to update — the drift .ai/rules/accounts.md exists to prevent.
 */
final readonly class DashboardScope
{
    public function __construct(private User $viewer) {}

    public function viewer(): User
    {
        return $this->viewer;
    }

    /**
     * Whether this person may see a module at all.
     */
    public function canSee(string $module): bool
    {
        return ActivityRelations::has($module)
            && $this->viewer->can($module.'.view');
    }

    /**
     * That module's records, scoped to what this person may see, or null when
     * they may not see the module.
     *
     * Null rather than an empty query on purpose: "no permission" and "no
     * records" are different answers, and a widget should say so differently.
     *
     * @return Builder<covariant Model>|null
     */
    public function query(string $module): ?Builder
    {
        if (! $this->canSee($module)) {
            return null;
        }

        return ActivityRelations::visibleQuery($module, $this->viewer);
    }

    /**
     * The modules this person may see, in the registry's own order.
     *
     * @return array<int, string>
     */
    public function modules(): array
    {
        return array_values(array_filter(
            ActivityRelations::keys(),
            fn (string $module): bool => $this->canSee($module),
        ));
    }

    public function seesAnything(): bool
    {
        return $this->modules() !== [];
    }

    /**
     * Activities are not in ActivityRelations — they are the thing that points
     * *at* those modules — so they get their own accessor, gated the same way.
     *
     * @return Builder<Activity>|null
     */
    public function activities(): ?Builder
    {
        if (! $this->viewer->can('activities.view')) {
            return null;
        }

        return Activity::query()->visibleTo($this->viewer);
    }
}
