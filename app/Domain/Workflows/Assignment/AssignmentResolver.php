<?php

namespace App\Domain\Workflows\Assignment;

use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\PipelineModules;
use App\Domain\Workflows\Models\WorkflowAction;
use App\Domain\Workflows\Runtime\WorkflowContext;
use App\Domain\Workflows\WorkflowModules;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Works out who a record should go to.
 *
 * Every strategy resolves through a **query for real users**, never straight
 * from the stored ids: a config written when somebody worked here outlives them
 * leaving, and handing records to a deleted user is how a pipeline silently
 * stops being worked.
 *
 * A strategy that finds nobody returns null, and the caller skips rather than
 * fails — a rule that is simply not applicable tonight should not put a red row
 * in the log.
 */
class AssignmentResolver
{
    public function resolve(WorkflowAction $action, WorkflowContext $context): ?User
    {
        $strategy = AssignmentStrategy::tryFrom((string) $action->setting('assign_to_strategy'))
            ?? $this->legacyStrategy($action);

        return match ($strategy) {
            AssignmentStrategy::Fixed => $this->fixed($action),
            AssignmentStrategy::RecordOwner => $this->recordOwner($context),
            AssignmentStrategy::RoundRobin => $this->roundRobin($action),
            AssignmentStrategy::LoadBased => $this->loadBased($action, $context),
            AssignmentStrategy::Territory => $this->territory($action, $context),
        };
    }

    /**
     * A step written before 5.7 carries only `assign_to`.
     *
     * Read rather than migrated: the old shape is unambiguous — `user:5` means
     * that person — and rewriting stored configs to add a key that can be
     * inferred is a migration that can go wrong for no gain.
     */
    private function legacyStrategy(WorkflowAction $action): AssignmentStrategy
    {
        return (string) $action->setting('assign_to') === 'record_owner'
            ? AssignmentStrategy::RecordOwner
            : AssignmentStrategy::Fixed;
    }

    private function fixed(WorkflowAction $action): ?User
    {
        return $this->user((string) $action->setting('assign_to'));
    }

    private function recordOwner(WorkflowContext $context): ?User
    {
        $ownerId = $context->subject?->getAttribute('owner_id');

        return $ownerId === null ? null : User::query()->whereKey((int) $ownerId)->first();
    }

    /**
     * The next person in the pool, by turn.
     *
     * The pointer is taken under a row lock so two records created in the same
     * moment cannot both be handed to the same person — see AssignmentPointer.
     */
    private function roundRobin(WorkflowAction $action): ?User
    {
        $pool = $this->pool($action);

        if ($pool->isEmpty()) {
            return null;
        }

        $index = AssignmentPointer::take('action:'.$action->id, $pool->count());

        return $pool->values()->get($index);
    }

    /**
     * Whoever in the pool is carrying the least.
     *
     * "Least" counts the records of **this module** they own and still have to
     * work — a closed deal is not a load, and counting it would leave the best
     * salesperson permanently at the back of the queue.
     *
     * Ties break on the pool's own order, so a tie is resolved the same way
     * every time rather than by whatever the database returns first.
     */
    private function loadBased(WorkflowAction $action, WorkflowContext $context): ?User
    {
        $pool = $this->pool($action);

        if ($pool->isEmpty()) {
            return null;
        }

        $counts = $this->openCounts($context->module(), $pool->pluck('id')->all());

        return $pool->sortBy(fn (User $user): int => $counts[$user->id] ?? 0)->first();
    }

    /**
     * The owner named for whatever the record says in the territory field.
     *
     * Matched case-insensitively and on the trimmed value, because a territory
     * list is typed by a person and a record's country is typed by another.
     */
    private function territory(WorkflowAction $action, WorkflowContext $context): ?User
    {
        $field = (string) $action->setting('territory_field');
        $column = WorkflowModules::column($context->module(), $field);
        $record = $context->subject;

        if ($column === null || $record === null) {
            return null;
        }

        $value = $this->normalise($record->getAttribute($column));
        $territories = $action->setting('territories');

        if ($value !== '' && is_array($territories)) {
            foreach ($territories as $territory) {
                if (! is_array($territory)) {
                    continue;
                }

                if ($this->normalise($territory['value'] ?? null) === $value) {
                    $owner = $this->user((string) ($territory['user'] ?? ''));

                    if ($owner !== null) {
                        return $owner;
                    }
                }
            }
        }

        // Nothing matched. A fallback is what stops a record from a country
        // nobody listed simply going nowhere.
        return $this->user((string) $action->setting('territory_fallback'));
    }

    /**
     * How many records of this module each person still has to work.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    private function openCounts(string $module, array $userIds): array
    {
        $model = WorkflowModules::modelClass($module);

        if ($model === null || $userIds === []) {
            return [];
        }

        $query = $model::query()->whereIn('owner_id', $userIds);

        // A generated module keeps every module's records in one table.
        $custom = WorkflowModules::customModule($module);

        if ($custom !== null) {
            $query->where('custom_module_id', $custom->id);
        }

        $this->excludeClosed($query, $module);

        // Counted through the Eloquent builder, not `getQuery()`: dropping to
        // the base builder discards the global scopes, and every module here
        // soft-deletes — a removed lead would count as work somebody is still
        // carrying. See .ai/rules/models.md.
        /** @var array<int, int> $counts */
        $counts = $query
            ->selectRaw('owner_id, count(*) as aggregate')
            ->groupBy('owner_id')
            ->pluck('aggregate', 'owner_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        return $counts;
    }

    /**
     * Leave out the records whose work is over.
     *
     * Read from the module's configured statuses rather than a hardcoded list,
     * so a pipeline an administrator rearranged still counts the right things.
     *
     * @param  Builder<covariant Model>  $query
     */
    private function excludeClosed(Builder $query, string $module): void
    {
        $column = PipelineModules::has($module) ? PipelineModules::column($module) : null;

        if ($column === null) {
            return;
        }

        $closed = [];

        foreach (PipelineModules::statuses($module) as $status) {
            if ($status['outcome'] !== StageOutcome::Open) {
                $closed[] = $status['value'];
            }
        }

        if ($closed !== []) {
            $query->whereNotIn($column, $closed);
        }
    }

    /**
     * The pool, as real users, in the order the step names them.
     *
     * @return Collection<int, User>
     */
    private function pool(WorkflowAction $action): Collection
    {
        $configured = $action->setting('pool');

        if (! is_array($configured)) {
            return new Collection;
        }

        $ids = [];

        foreach ($configured as $value) {
            $id = (int) str((string) $value)->after('user:')->toString();

            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return new Collection;
        }

        $found = User::query()->whereIn('id', $ids)->get()->keyBy('id');

        // Rebuilt in the configured order: round robin's fairness is an order,
        // and taking whatever order the database returned would make the turn
        // sequence arbitrary.
        return new Collection(array_values(array_filter(
            array_map(fn (int $id): ?User => $found->get($id), $ids)
        )));
    }

    private function user(string $reference): ?User
    {
        if (! str_starts_with($reference, 'user:')) {
            return null;
        }

        return User::query()->whereKey((int) str($reference)->after('user:')->toString())->first();
    }

    private function normalise(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }
}
