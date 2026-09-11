<?php

namespace App\Domain\Workflows\Handlers;

use App\Domain\Deals\Actions\MoveDealStageAction;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\PipelineModules;
use App\Domain\Leads\Actions\ChangeLeadStatusAction;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Workflows\Models\WorkflowAction;
use App\Domain\Workflows\Runtime\WorkflowContext;
use App\Domain\Workflows\Runtime\WorkflowStepOutcome;
use App\Domain\Workflows\WorkflowModules;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Sets one field on the record the workflow fired for.
 *
 * Two rules, both load-bearing:
 *
 * - **The field must be one the module declares and the model allows.** The key
 *   is looked up in `WorkflowModules::writableFields()`, which is the module's
 *   own field set intersected with what the model is willing to be filled
 *   with. A stored config can therefore never name `id`, a timestamp, or a
 *   column the module does not admit to having.
 * - **A status goes through the action that owns it.** `ChangeLeadStatusAction`
 *   describes itself as the only thing that moves a lead's status, and it means
 *   it: the transition rules and the qualification checks live there. A
 *   workflow writing the column directly would be the one route that skips
 *   them. So the status column is routed, and a refused transition becomes a
 *   failed step whose message is the reason — "a converted lead cannot move to
 *   new" — which is exactly what somebody reading the log needs.
 */
class UpdateFieldHandler implements WorkflowActionHandler
{
    public function handle(WorkflowAction $action, WorkflowContext $context): WorkflowStepOutcome
    {
        $record = $context->subject;

        if ($record === null) {
            return WorkflowStepOutcome::skipped('There is no record to update.');
        }

        $module = $context->module();
        $key = (string) $action->setting('field');
        $value = $action->setting('value');

        $field = WorkflowModules::writableFields($module)[$key] ?? null;

        if ($field === null) {
            return WorkflowStepOutcome::failed(
                WorkflowModules::label($module).' has no field "'.$key.'" a workflow can set.'
            );
        }

        if ($field->isCustomField()) {
            return $this->setCustomField($record, $key, $value);
        }

        if ($key === PipelineModules::column($module)) {
            return $this->moveStatus($record, $module, (string) $value);
        }

        $record->forceFill([$field->column() => $value])->save();

        return WorkflowStepOutcome::success(
            'Set '.strtolower($field->label).' to '.$this->readable($value),
            ['field' => $key, 'value' => $value],
        );
    }

    private function setCustomField(Model $record, string $prefixedKey, mixed $value): WorkflowStepOutcome
    {
        if (! method_exists($record, 'saveCustomFields')) {
            return WorkflowStepOutcome::failed('This record does not carry custom fields.');
        }

        $key = (string) str($prefixedKey)->after('cf_');

        $record->saveCustomFields([$key => $value]);

        return WorkflowStepOutcome::success(
            'Set '.$key.' to '.$this->readable($value),
            ['field' => $prefixedKey, 'value' => $value],
        );
    }

    /**
     * A status move, through whatever owns it for this module.
     */
    private function moveStatus(Model $record, string $module, string $value): WorkflowStepOutcome
    {
        try {
            return match (true) {
                $record instanceof Lead => $this->moveLead($record, $value),
                $record instanceof Deal => $this->moveDeal($record, $value),
                // Activities move through complete/reopen/cancel, each of which
                // does more than write a column — a completion stamps a time and
                // can close a recurrence. Routing a generic "set the field" at
                // one of them would pick an action from a string, so this says
                // no rather than guessing.
                default => WorkflowStepOutcome::failed(
                    WorkflowModules::label($module).' status is not something a workflow sets directly.'
                ),
            };
        } catch (RuntimeException $refused) {
            // The transition rules refusing the move is a real answer, and its
            // message is the one worth logging.
            return WorkflowStepOutcome::failed($refused->getMessage());
        }
    }

    private function moveLead(Lead $lead, string $value): WorkflowStepOutcome
    {
        $target = LeadStatus::tryFrom($value);

        if ($target === null) {
            return WorkflowStepOutcome::failed('"'.$value.'" is not a lead status.');
        }

        app(ChangeLeadStatusAction::class)($lead, $target);

        return WorkflowStepOutcome::success(
            'Moved the lead to '.strtolower($target->label()),
            ['field' => 'status', 'value' => $target->value],
        );
    }

    private function moveDeal(Deal $deal, string $value): WorkflowStepOutcome
    {
        $moved = app(MoveDealStageAction::class)($deal, $value);

        if (! $moved) {
            return WorkflowStepOutcome::failed('"'.$value.'" is not a stage of this deal\'s pipeline.');
        }

        return WorkflowStepOutcome::success(
            'Moved the deal to '.$value,
            ['field' => 'stage', 'value' => $value],
        );
    }

    private function readable(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => 'nothing',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => implode(', ', array_map('strval', $value)),
            default => (string) $value,
        };
    }
}
