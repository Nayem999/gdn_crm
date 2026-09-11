<?php

namespace App\Domain\Workflows\Conditions;

use App\Domain\Shared\Filters\FilterApplier;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\WorkflowModules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Decides whether a record satisfies a workflow's conditions.
 *
 * **In SQL, not in PHP.** The condition is handed to the same `FilterApplier`
 * the list screens use, against a query narrowed to the one record, and the
 * answer is whether that query still returns it. Writing a second comparator in
 * PHP would mean two implementations of "does not contain", "is between" and
 * "is empty", and they would agree until the first NULL — a workflow would then
 * act on records its own filter chip says it should not.
 *
 * What that buys, for free and correctly: AND/OR nesting, NULL-aware negation,
 * the database's own collation for case handling, date boundaries, and custom
 * fields through the EXISTS subquery 4.2 already built.
 *
 * The query is **not** scoped to a user. A workflow is not somebody looking at
 * a list; it acts on behalf of the organisation, and a condition that depended
 * on who happened to trigger it would make the same record match or not
 * depending on who edited it.
 */
class WorkflowConditions
{
    public function __construct(private readonly FilterApplier $applier) {}

    /**
     * Whether this record satisfies the workflow's conditions.
     *
     * A workflow with no conditions matches everything — that is what an empty
     * condition tree means, and it is the common case.
     */
    public function matches(Workflow $workflow, Model $record): bool
    {
        $group = $workflow->conditions();

        if ($group->isEmpty()) {
            return true;
        }

        $fields = WorkflowModules::fields($workflow->module());

        return $this->applier
            ->apply($this->subject($record), $group, $fields)
            ->exists();
    }

    /**
     * The one record, as a query the applier can constrain.
     *
     * The soft-delete scope is lifted wherever the model has one: a delete
     * trigger fires on a record that has just gone, and the default scope would
     * hide it from its own workflow's conditions — every condition would
     * evaluate false and no delete workflow with conditions could ever run.
     *
     * `withoutGlobalScope()` rather than `withTrashed()` because the latter
     * only exists on a builder the type system knows is soft-deleting, and this
     * one is only known to be a builder.
     *
     * @return Builder<covariant Model>
     */
    private function subject(Model $record): Builder
    {
        $query = $record->newQuery()->whereKey($record->getKey());

        if (in_array(SoftDeletes::class, class_uses_recursive($record), true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $query;
    }
}
