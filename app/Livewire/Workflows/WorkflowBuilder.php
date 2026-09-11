<?php

namespace App\Livewire\Workflows;

use App\Domain\CustomFields\CustomFieldRegistry;
use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Shared\Concerns\EditsConditions;
use App\Domain\Shared\Filters\FilterField;
use App\Domain\Shared\Filters\FilterGroup;
use App\Domain\Workflows\Actions\SaveWorkflowAction;
use App\Domain\Workflows\Conditions\WorkflowConditions;
use App\Domain\Workflows\DTOs\WorkflowData;
use App\Domain\Workflows\Enums\WorkflowActionType;
use App\Domain\Workflows\Enums\WorkflowTrigger;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\WorkflowModules;
use App\Models\User;
use Cron\CronExpression;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Builds one workflow: what fires it, what it checks, what it does.
 *
 * The three parts are edited together on one screen because they are one
 * decision — a trigger without actions does nothing and actions without a
 * trigger never run — and saved together by `SaveWorkflowAction`, which
 * reconciles the steps rather than replacing them.
 *
 * Conditions reuse `EditsConditions` and the filter builder's own condition
 * component. That is not only less code: it is what makes a condition shown
 * here and a condition evaluated by 5.3 the same thing.
 *
 * The **module is chosen once**, on create. Everything else on the screen
 * depends on it — the field a trigger watches, every condition, every field a
 * step can set — so changing it later would invalidate the whole definition.
 * After create it is shown and not editable.
 */
#[Title('Workflow')]
class WorkflowBuilder extends Component
{
    use AuthorizesRequests;
    use EditsConditions;

    public ?int $workflowId = null;

    public string $name = '';

    public string $description = '';

    public string $module = 'leads';

    public string $triggerEvent = WorkflowTrigger::RecordCreated->value;

    public string $triggerField = '';

    public string $dateOffset = '0';

    public string $dateOffsetDirection = 'after';

    public string $scheduleExpression = '0 9 * * *';

    public bool $runOncePerRecord = false;

    public bool $isActive = false;

    /**
     * The condition tree. Declared here rather than inherited because a list
     * screen puts `#[Url]` on its copy and a builder must not — see
     * EditsConditions.
     *
     * @var array<string, mixed>
     */
    public array $filters = FilterGroup::EMPTY;

    /**
     * The steps, in order.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $steps = [];

    /**
     * What a dry run said this workflow would do to a chosen record.
     *
     * @var array<string, mixed>|null
     */
    public ?array $dryRun = null;

    public string $dryRunRecordId = '';

    public ?string $saved = null;

    public function mount(?Workflow $workflow = null): void
    {
        if ($workflow?->exists) {
            $this->authorize('update', $workflow);
            $this->fill_from($workflow);

            return;
        }

        $this->authorize('create', Workflow::class);
        $this->addStep(WorkflowActionType::UpdateField->value);
    }

    // -- Options ----------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    public function moduleOptions(): array
    {
        return WorkflowModules::options();
    }

    /**
     * @return array<string, string>
     */
    public function triggerOptions(): array
    {
        return WorkflowTrigger::options();
    }

    /**
     * @return array<string, string>
     */
    public function triggerFieldOptions(): array
    {
        return $this->trigger()->needsDateField()
            ? WorkflowModules::dateFieldOptions($this->module)
            : WorkflowModules::fieldOptions($this->module);
    }

    /**
     * @return array<string, string>
     */
    public function writableFieldOptions(): array
    {
        return WorkflowModules::writableFieldOptions($this->module);
    }

    /**
     * @return array<string, string>
     */
    public function actionTypeOptions(): array
    {
        return WorkflowActionType::options();
    }

    /**
     * @return array<int, string>
     */
    public function userOptions(): array
    {
        return User::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * The fields conditions are built on: the module's own set.
     *
     * @return array<int, FilterField>
     */
    public function conditionFields(): array
    {
        return array_values(WorkflowModules::fields($this->module));
    }

    public function trigger(): WorkflowTrigger
    {
        return WorkflowTrigger::tryFrom($this->triggerEvent) ?? WorkflowTrigger::RecordCreated;
    }

    // -- Editing ------------------------------------------------------------------

    public function updatedFilters(): void
    {
        $this->normaliseConditions();
    }

    /**
     * Changing the module invalidates everything chosen against the old one.
     *
     * Cleared rather than kept: a condition on a field the new module does not
     * have would be silently dropped on save, and the screen would then show
     * something different from what was stored.
     */
    public function updatedModule(): void
    {
        $this->filters = FilterGroup::EMPTY;
        $this->triggerField = '';
        $this->steps = [];
        $this->dryRun = null;
        $this->addStep(WorkflowActionType::UpdateField->value);
    }

    public function updatedTriggerEvent(): void
    {
        // A field chosen for a change trigger is not a date, so it cannot carry
        // over to a date trigger — and neither means anything to the others.
        $this->triggerField = '';
        $this->dryRun = null;
    }

    public function addStep(?string $type = null): void
    {
        $this->steps[] = [
            'id' => null,
            'type' => $type ?? WorkflowActionType::UpdateField->value,
            'config' => [],
            'is_active' => true,
            'stop_on_failure' => true,
        ];
    }

    public function removeStep(int $index): void
    {
        unset($this->steps[$index]);
        $this->steps = array_values($this->steps);
    }

    public function moveStep(int $index, int $by): void
    {
        $target = $index + $by;

        if (! isset($this->steps[$index], $this->steps[$target])) {
            return;
        }

        [$this->steps[$index], $this->steps[$target]] = [$this->steps[$target], $this->steps[$index]];
    }

    /**
     * A changed type keeps nothing from the old one: config keys mean different
     * things per type, and a leftover `url` on an email step would be a setting
     * nobody can see and nothing reads.
     */
    public function updatedSteps(mixed $value, ?string $key = null): void
    {
        if ($key === null || ! str_ends_with($key, '.type')) {
            return;
        }

        $index = (int) str($key)->before('.type')->toString();

        if (isset($this->steps[$index])) {
            $this->steps[$index]['config'] = [];
        }
    }

    // -- Saving ---------------------------------------------------------------------

    public function save(): void
    {
        $workflow = $this->workflowId === null
            ? null
            : Workflow::query()->whereKey($this->workflowId)->first();

        $this->authorize($workflow === null ? 'create' : 'update', $workflow ?? Workflow::class);

        $this->validate($this->rules(), [], [
            'triggerField' => 'field to watch',
            'scheduleExpression' => 'schedule',
            'steps' => 'steps',
        ]);

        $saved = app(SaveWorkflowAction::class)($this->definition(), $workflow);

        $this->workflowId = $saved->id;
        $this->fill_from($saved);
        $this->saved = 'Saved.';

        $this->dispatch('notify', type: 'success', message: $saved->name.' saved.');
    }

    /**
     * The definition as the action wants it.
     *
     * Built in one place so the dry run and the save read the same thing — a
     * preview of something other than what would be stored is worse than none.
     */
    private function definition(): WorkflowData
    {
        return WorkflowData::fromArray([
            'name' => $this->name,
            'description' => $this->description,
            'module' => $this->module,
            'trigger_event' => $this->triggerEvent,
            'trigger_field' => $this->triggerField,
            'date_offset_minutes' => $this->offsetMinutes(),
            'schedule_expression' => $this->scheduleExpression,
            'conditions' => $this->filters,
            'is_active' => $this->isActive,
            'run_once_per_record' => $this->runOncePerRecord,
            'actions' => array_map(fn (array $step): array => [
                'id' => $step['id'] ?? null,
                'type' => $step['type'] ?? '',
                'config' => $step['config'] ?? [],
                'is_active' => (bool) ($step['is_active'] ?? true),
                'stop_on_failure' => (bool) ($step['stop_on_failure'] ?? true),
            ], $this->steps),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $trigger = $this->trigger();

        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'module' => ['required', Rule::in(WorkflowModules::keys())],
            'triggerEvent' => ['required', Rule::in(array_keys(WorkflowTrigger::options()))],
            // Only required when the trigger has something to watch, and only
            // ever one of that module's own fields.
            'triggerField' => $trigger->needsField()
                ? ['required', Rule::in(array_keys($this->triggerFieldOptions()))]
                : ['nullable'],
            'scheduleExpression' => $trigger->needsSchedule()
                ? ['required', 'string', function (string $attribute, mixed $value, callable $fail): void {
                    if (! CronExpression::isValidExpression((string) $value)) {
                        $fail('That is not a schedule this can read. Try "0 9 * * 1-5" for weekdays at nine.');
                    }
                }]
                : ['nullable'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.type' => ['required', Rule::in(array_keys(WorkflowActionType::options()))],
        ];
    }

    private function offsetMinutes(): int
    {
        $minutes = max(0, (int) $this->dateOffset);

        return $this->dateOffsetDirection === 'before' ? -$minutes : $minutes;
    }

    // -- Dry run ----------------------------------------------------------------------

    /**
     * What this workflow would do to one chosen record, without doing it.
     *
     * The question somebody actually has when a workflow is not behaving:
     * "would this fire for that lead?". Conditions are evaluated for real —
     * against the unsaved definition on screen, so it answers for what is being
     * edited rather than what was last saved — and the steps are described
     * rather than run.
     */
    public function testAgainst(): void
    {
        $record = $this->dryRunRecord();

        if ($record === null) {
            $this->dryRun = ['found' => false];

            return;
        }

        $draft = new Workflow($this->definition()->toAttributes());

        $this->dryRun = [
            'found' => true,
            'label' => CustomFieldRegistry::recordLabel($record),
            'matches' => app(WorkflowConditions::class)->matches($draft, $record),
            'steps' => array_values(array_map(
                fn (array $step): string => $this->describe($step),
                array_filter($this->steps, fn (array $step): bool => (bool) ($step['is_active'] ?? true)),
            )),
        ];
    }

    private function dryRunRecord(): ?Model
    {
        $id = (int) $this->dryRunRecordId;
        $model = WorkflowModules::modelClass($this->module);

        if ($id <= 0 || $model === null) {
            return null;
        }

        $query = $model::query()->whereKey($id);

        // Records of a generated module share one table.
        $custom = WorkflowModules::customModule($this->module);

        if ($custom !== null) {
            $query->where('custom_module_id', $custom->id);
        }

        return $query->first();
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function describe(array $step): string
    {
        $type = WorkflowActionType::tryFrom((string) ($step['type'] ?? '')) ?? WorkflowActionType::UpdateField;
        $config = is_array($step['config'] ?? null) ? $step['config'] : [];

        return match ($type) {
            WorkflowActionType::UpdateField => 'Set '.($this->writableFieldOptions()[$config['field'] ?? ''] ?? '?')
                .' to "'.($config['value'] ?? '').'"',
            WorkflowActionType::AssignOwner => 'Assign to '.($this->userOptions()[(int) str((string) ($config['assign_to'] ?? ''))->after('user:')->toString()] ?? '?'),
            WorkflowActionType::CreateRecord => 'Create a '.WorkflowModules::label((string) ($config['module'] ?? '')).' record',
            WorkflowActionType::SendEmail => 'Email '.($config['recipient'] === 'record_email' ? 'the record' : (string) ($config['recipient'] ?? '?')),
            WorkflowActionType::SendNotification => 'Notify '.($config['recipient'] === 'record_owner' ? 'the owner' : 'a user'),
            WorkflowActionType::CallWebhook => 'POST to '.(string) ($config['url'] ?? '?'),
            WorkflowActionType::RequestApproval => 'Stop and ask '
                .count(is_array($config['approvers'] ?? null) ? $config['approvers'] : [])
                .' '.str('person')->plural(count(is_array($config['approvers'] ?? null) ? $config['approvers'] : []))
                .' — everything below waits',
        };
    }

    // -- Loading ----------------------------------------------------------------------

    private function fill_from(Workflow $workflow): void
    {
        $this->workflowId = $workflow->id;
        $this->name = $workflow->name;
        $this->description = (string) $workflow->description;
        $this->module = $workflow->module();
        $this->triggerEvent = $workflow->trigger_event;
        $this->triggerField = (string) $workflow->trigger_field;
        $this->scheduleExpression = (string) ($workflow->schedule_expression ?: '0 9 * * *');
        $this->runOncePerRecord = (bool) $workflow->run_once_per_record;
        $this->isActive = (bool) $workflow->is_active;

        $offset = (int) ($workflow->date_offset_minutes ?? 0);
        $this->dateOffsetDirection = $offset < 0 ? 'before' : 'after';
        $this->dateOffset = (string) abs($offset);

        $stored = $workflow->getAttributeValue('conditions');
        $this->filters = is_array($stored) && $stored !== [] ? $stored : FilterGroup::EMPTY;

        $this->steps = $workflow->actions->map(fn ($step): array => [
            'id' => $step->id,
            'type' => $step->type()->value,
            'config' => $step->config(),
            'is_active' => (bool) $step->is_active,
            'stop_on_failure' => (bool) $step->stop_on_failure,
        ])->all();

        if ($this->steps === []) {
            $this->addStep();
        }
    }

    /**
     * @return array<string, string>
     */
    public function recipientOptions(): array
    {
        return [
            'record_owner' => 'Whoever owns the record',
            ...collect($this->userOptions())->mapWithKeys(
                fn (string $name, int $id): array => ['user:'.$id => $name]
            )->all(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function emailRecipientOptions(): array
    {
        return ['record_email' => 'The address on the record'];
    }

    /**
     * @return array<string, string>
     */
    public function notificationRecipientTypes(): array
    {
        return [RecipientType::AssignedAgent->value => 'The assigned person'];
    }

    public function render(): View
    {
        return view('livewire.workflows.workflow-builder');
    }
}
