<?php

namespace App\Domain\Workflows\Handlers;

use App\Domain\Activities\Enums\ActivityType;
use App\Domain\Activities\Models\Activity;
use App\Domain\Workflows\Models\WorkflowAction;
use App\Domain\Workflows\Runtime\WorkflowContext;
use App\Domain\Workflows\Runtime\WorkflowStepOutcome;
use App\Domain\Workflows\WorkflowModules;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * Creates a related record — in practice, a follow-up task.
 *
 * Activities are handled first-class, because that is what this action is for
 * and because an activity created by a workflow has to be **linked back to the
 * record that caused it**. A follow-up task floating free of the lead it is
 * about is worse than no task: somebody finds it in their list with no way to
 * know what it refers to.
 *
 * Other modules are created generically from the configured values, restricted
 * to that module's writable fields. A module whose required columns the config
 * does not supply produces a failed step saying what the database refused,
 * rather than a half-written record.
 *
 * The new record's owner defaults to the triggering record's owner, which is
 * almost always who should be doing the follow-up.
 */
class CreateRecordHandler implements WorkflowActionHandler
{
    public function handle(WorkflowAction $action, WorkflowContext $context): WorkflowStepOutcome
    {
        $module = (string) $action->setting('module');

        if (! WorkflowModules::has($module)) {
            return WorkflowStepOutcome::failed('"'.$module.'" is not a module a workflow can create in.');
        }

        $owner = $this->owner($action, $context);

        if ($owner === null) {
            return WorkflowStepOutcome::failed('There is nobody to own the new record.');
        }

        $values = $this->values($action, $module);

        return $module === 'activities'
            ? $this->createActivity($action, $context, $owner, $values)
            : $this->createRecord($module, $owner, $values);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function createActivity(
        WorkflowAction $action,
        WorkflowContext $context,
        User $owner,
        array $values,
    ): WorkflowStepOutcome {
        $subject = trim((string) ($values['subject'] ?? $action->setting('subject') ?? ''));

        if ($subject === '') {
            return WorkflowStepOutcome::failed('A task needs a subject.');
        }

        $activity = new Activity;

        $activity->forceFill([
            ...$values,
            'type' => (ActivityType::tryFrom((string) $action->setting('type')) ?? ActivityType::Task)->value,
            'subject' => $subject,
            // Days after the workflow fired, so "call them back in three days"
            // is a number in the config rather than a date that goes stale.
            'due_at' => now()->addDays((int) ($action->setting('due_in_days') ?? 1)),
            'owner_id' => $owner->id,
            // Linked back to what caused it. Without this the task is a note to
            // nobody about nothing.
            'related_type' => $context->subject?->getMorphClass(),
            'related_id' => $context->subject?->getKey(),
        ])->save();

        return WorkflowStepOutcome::success(
            'Created the task "'.$subject.'" for '.$owner->name,
            ['module' => 'activities', 'id' => $activity->id],
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function createRecord(string $module, User $owner, array $values): WorkflowStepOutcome
    {
        $model = WorkflowModules::modelClass($module);

        if ($model === null) {
            return WorkflowStepOutcome::failed('"'.$module.'" has no model.');
        }

        $record = new $model;

        try {
            $record->forceFill([...$values, 'owner_id' => $owner->id])->save();
        } catch (QueryException $refused) {
            // A module whose required columns the config did not supply. The
            // database's own complaint is more use in the log than a guess.
            return WorkflowStepOutcome::failed(
                'Could not create the '.WorkflowModules::label($module).' record: '.$refused->getMessage()
            );
        }

        return WorkflowStepOutcome::success(
            'Created a '.WorkflowModules::label($module).' record',
            ['module' => $module, 'id' => $record->getKey()],
        );
    }

    /**
     * The configured values, restricted to fields that module admits to having.
     *
     * @return array<string, mixed>
     */
    private function values(WorkflowAction $action, string $module): array
    {
        $configured = $action->setting('values');

        if (! is_array($configured)) {
            return [];
        }

        $writable = WorkflowModules::writableFields($module);
        $values = [];

        foreach ($configured as $key => $value) {
            $field = $writable[(string) $key] ?? null;

            // Custom fields are not columns, so they cannot be written by an
            // insert. A workflow that needs to answer one on a new record does
            // it with a second step on the created record.
            if ($field !== null && ! $field->isCustomField()) {
                $values[$field->column()] = $value;
            }
        }

        return $values;
    }

    private function owner(WorkflowAction $action, WorkflowContext $context): ?User
    {
        $rule = (string) ($action->setting('owner') ?? 'record_owner');

        if (str_starts_with($rule, 'user:')) {
            return User::query()->whereKey((int) str($rule)->after('user:')->toString())->first();
        }

        $ownerId = $context->subject?->getAttribute('owner_id');

        return $ownerId === null ? null : User::query()->whereKey((int) $ownerId)->first();
    }
}
