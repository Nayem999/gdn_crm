<?php

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\PermissionResolver;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Enums\FilterFieldType;
use App\Domain\Shared\Enums\FilterOperator;
use App\Domain\Shared\Filters\FilterGroup;
use App\Domain\Workflows\Actions\DeleteWorkflowAction;
use App\Domain\Workflows\Actions\ReorderWorkflowsAction;
use App\Domain\Workflows\Actions\SaveWorkflowAction;
use App\Domain\Workflows\Actions\ToggleWorkflowAction;
use App\Domain\Workflows\DTOs\WorkflowData;
use App\Domain\Workflows\Enums\WorkflowActionType;
use App\Domain\Workflows\Enums\WorkflowRunStatus;
use App\Domain\Workflows\Enums\WorkflowTrigger;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowAction;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Models\WorkflowRunStep;
use App\Domain\Workflows\WorkflowModules;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * A definition as a builder would submit it.
 *
 * @param  array<string, mixed>  $overrides
 */
function workflowData(array $overrides = []): WorkflowData
{
    return WorkflowData::fromArray([
        'name' => 'Welcome a new lead',
        'module' => 'leads',
        'trigger_event' => WorkflowTrigger::RecordCreated->value,
        'actions' => [
            ['type' => WorkflowActionType::UpdateField->value, 'config' => ['field' => 'status', 'value' => 'contacted']],
        ],
        ...$overrides,
    ]);
}

function saveWorkflow(WorkflowData $data, ?Workflow $workflow = null): Workflow
{
    return app(SaveWorkflowAction::class)($data, $workflow);
}

// -- Creating and updating a definition ----------------------------------------

test('a workflow saves with its trigger, conditions and steps', function () {
    $workflow = saveWorkflow(workflowData([
        'description' => 'Chase anything worth chasing.',
        'conditions' => [
            'match' => FilterGroup::MATCH_ALL,
            'conditions' => [
                ['field' => 'estimated_value', 'operator' => FilterOperator::GreaterThan->value, 'value' => '5000'],
            ],
            'groups' => [],
        ],
    ]));

    expect($workflow->name)->toBe('Welcome a new lead')
        ->and($workflow->module())->toBe('leads')
        ->and($workflow->trigger())->toBe(WorkflowTrigger::RecordCreated)
        ->and($workflow->actions)->toHaveCount(1)
        ->and($workflow->actions->first()->type())->toBe(WorkflowActionType::UpdateField);

    // The condition comes back as the same thing the filter builder edits.
    $group = $workflow->conditions();

    expect($group->count())->toBe(1)
        ->and($group->conditions[0]->field)->toBe('estimated_value')
        ->and($group->conditions[0]->operator)->toBe(FilterOperator::GreaterThan);
});

test('a new workflow is off until somebody turns it on', function () {
    // It changes records on everybody's behalf. Saving a draft must not start
    // it doing that.
    expect(saveWorkflow(workflowData())->is_active)->toBeFalse();
});

test('a module the registry does not list is refused', function () {
    // The module decides which records are touched and which field set the
    // conditions are checked against. A payload that could name one would be
    // naming a table.
    expect(fn () => saveWorkflow(workflowData(['module' => 'shadow_table'])))
        ->toThrow(RuntimeException::class);

    expect(Workflow::query()->count())->toBe(0);
});

test('a workflow cannot be moved to another module', function () {
    // Its conditions and its steps all name fields of the module it was built
    // for. Moving it would leave every one of them pointing at a field the new
    // module does not have, and it would quietly stop matching rather than fail.
    $workflow = saveWorkflow(workflowData());

    saveWorkflow(workflowData(['name' => 'Renamed', 'module' => 'deals']), $workflow);

    expect($workflow->fresh()->module())->toBe('leads')
        ->and($workflow->fresh()->name)->toBe('Renamed');
});

test('workflows are appended within their own module', function () {
    saveWorkflow(workflowData());
    saveWorkflow(workflowData(['name' => 'Second']));
    $onDeals = saveWorkflow(workflowData(['name' => 'Deals one', 'module' => 'deals']));

    expect(Workflow::query()->where('name', 'Second')->value('position'))->toBe(2)
        // A module's numbering starts over; a deals workflow does not inherit
        // a position from the leads list.
        ->and($onDeals->position)->toBe(1);
});

// -- What a definition may not say ---------------------------------------------

test('a trigger field is kept only when the trigger has one to watch', function () {
    $watching = saveWorkflow(workflowData([
        'trigger_event' => WorkflowTrigger::FieldChanged->value,
        'trigger_field' => 'status',
    ]));

    expect($watching->trigger_field)->toBe('status');

    // Switching to a trigger with nothing to watch clears it, rather than
    // leaving a field behind that nothing reads.
    saveWorkflow(workflowData([
        'trigger_event' => WorkflowTrigger::RecordCreated->value,
        'trigger_field' => 'status',
    ]), $watching);

    expect($watching->fresh()->trigger_field)->toBeNull();
});

test('a field the module does not have is dropped, not stored', function () {
    $workflow = saveWorkflow(workflowData([
        'trigger_event' => WorkflowTrigger::FieldChanged->value,
        'trigger_field' => 'password',
    ]));

    expect($workflow->trigger_field)->toBeNull()
        // And the workflow is not runnable, so nothing fires on a trigger that
        // watches nothing.
        ->and($workflow->isRunnable())->toBeFalse();
});

test('a date trigger only accepts a date field', function () {
    $text = saveWorkflow(workflowData([
        'trigger_event' => WorkflowTrigger::DateReached->value,
        'trigger_field' => 'first_name',
    ]));

    expect($text->trigger_field)->toBeNull();

    $date = saveWorkflow(workflowData([
        'trigger_event' => WorkflowTrigger::DateReached->value,
        'trigger_field' => 'created_at',
        'date_offset_minutes' => -60,
    ]));

    expect($date->trigger_field)->toBe('created_at')
        ->and($date->date_offset_minutes)->toBe(-60);
});

test('an offset belongs only to a date trigger', function () {
    $workflow = saveWorkflow(workflowData([
        'trigger_event' => WorkflowTrigger::RecordCreated->value,
        'date_offset_minutes' => -60,
    ]));

    expect($workflow->date_offset_minutes)->toBeNull();
});

test('a half-filled condition is dropped rather than stored', function () {
    // The same rule the filter builder follows: an incomplete row is ignored,
    // never treated as matching everything.
    $workflow = saveWorkflow(workflowData([
        'conditions' => [
            'match' => FilterGroup::MATCH_ANY,
            'conditions' => [
                ['field' => 'status', 'operator' => 'not_a_real_operator', 'value' => 'new'],
                ['field' => '', 'operator' => FilterOperator::Equals->value, 'value' => 'x'],
            ],
            'groups' => [],
        ],
    ]));

    expect($workflow->conditions()->count())->toBe(0)
        ->and($workflow->conditions()->match)->toBe(FilterGroup::MATCH_ANY);
});

test('a nested condition group survives the round trip', function () {
    // 5.3 needs AND/OR groups; this is what proves the storage carries them.
    $workflow = saveWorkflow(workflowData([
        'conditions' => [
            'match' => FilterGroup::MATCH_ALL,
            'conditions' => [
                ['field' => 'status', 'operator' => FilterOperator::Equals->value, 'value' => 'new'],
            ],
            'groups' => [
                [
                    'match' => FilterGroup::MATCH_ANY,
                    'conditions' => [
                        ['field' => 'city', 'operator' => FilterOperator::Contains->value, 'value' => 'Dhaka'],
                        ['field' => 'score', 'operator' => FilterOperator::GreaterThan->value, 'value' => '50'],
                    ],
                    'groups' => [],
                ],
            ],
        ],
    ]));

    $group = $workflow->fresh()->conditions();

    expect($group->count())->toBe(3)
        ->and($group->groups)->toHaveCount(1)
        ->and($group->groups[0]->matchAny())->toBeTrue();
});

// -- Steps ----------------------------------------------------------------------

test('steps are stored in the order they were submitted', function () {
    $workflow = saveWorkflow(workflowData([
        'actions' => [
            ['type' => WorkflowActionType::SendNotification->value, 'config' => ['event' => 'lead.assigned', 'recipient' => 'owner']],
            ['type' => WorkflowActionType::UpdateField->value, 'config' => ['field' => 'status', 'value' => 'contacted']],
        ],
    ]));

    expect($workflow->actions->pluck('type')->all())->toBe([
        WorkflowActionType::SendNotification->value,
        WorkflowActionType::UpdateField->value,
    ])->and($workflow->actions->pluck('position')->all())->toBe([0, 1]);
});

test('an edited step keeps its id, so the log stays attached to it', function () {
    // Delete-and-reinsert would hand every step a new id on every save, and
    // workflow_run_steps point at those ids — the whole history would detach.
    $workflow = saveWorkflow(workflowData());
    $step = $workflow->actions->first();

    $run = WorkflowRun::factory()->forWorkflow($workflow)->create();
    WorkflowRunStep::factory()->for($run, 'run')->forAction($step)->create();

    saveWorkflow(workflowData([
        'actions' => [
            ['id' => $step->id, 'type' => WorkflowActionType::UpdateField->value, 'config' => ['field' => 'status', 'value' => 'qualified']],
        ],
    ]), $workflow);

    expect($workflow->fresh()->actions->first()->id)->toBe($step->id)
        ->and($workflow->fresh()->actions->first()->setting('value'))->toBe('qualified')
        ->and(WorkflowRunStep::query()->first()->workflow_action_id)->toBe($step->id);
});

test('a step left out of the payload is removed', function () {
    $workflow = saveWorkflow(workflowData([
        'actions' => [
            ['type' => WorkflowActionType::UpdateField->value, 'config' => ['field' => 'status', 'value' => 'contacted']],
            ['type' => WorkflowActionType::CallWebhook->value, 'config' => ['url' => 'https://example.com/hook']],
        ],
    ]));

    expect($workflow->actions)->toHaveCount(2);

    saveWorkflow(workflowData(['actions' => []]), $workflow);

    expect($workflow->fresh()->actions)->toHaveCount(0);
});

test('a step id belonging to another workflow is not stolen', function () {
    $mine = saveWorkflow(workflowData());
    $theirs = saveWorkflow(workflowData(['name' => 'Somebody else']));
    $theirStep = $theirs->actions->first();

    saveWorkflow(workflowData([
        'actions' => [
            ['id' => $theirStep->id, 'type' => WorkflowActionType::UpdateField->value, 'config' => ['field' => 'status', 'value' => 'stolen']],
        ],
    ]), $mine);

    // Theirs is untouched, and mine got a new step rather than their row.
    expect($theirStep->fresh()->workflow_id)->toBe($theirs->id)
        ->and($theirStep->fresh()->setting('value'))->toBe('contacted')
        ->and($mine->fresh()->actions->first()->id)->not->toBe($theirStep->id);
});

test('a step is incomplete until it carries what its type needs', function () {
    $step = WorkflowAction::factory()->ofType(WorkflowActionType::SendEmail, ['template' => 'welcome'])->create();

    expect($step->isComplete())->toBeFalse();

    $step->forceFill(['config' => ['template' => 'welcome', 'recipient' => 'owner']])->save();

    expect($step->fresh()->isComplete())->toBeTrue();
});

// -- Switching one on -----------------------------------------------------------

test('a workflow with no steps cannot be switched on', function () {
    $workflow = saveWorkflow(workflowData(['actions' => []]));

    expect(fn () => app(ToggleWorkflowAction::class)($workflow, true))
        ->toThrow(RuntimeException::class);

    expect($workflow->fresh()->is_active)->toBeFalse();
});

test('a workflow whose only step is switched off cannot be switched on', function () {
    $workflow = saveWorkflow(workflowData([
        'actions' => [
            ['type' => WorkflowActionType::UpdateField->value, 'config' => ['field' => 'status', 'value' => 'x'], 'is_active' => false],
        ],
    ]));

    expect(fn () => app(ToggleWorkflowAction::class)($workflow, true))
        ->toThrow(RuntimeException::class);
});

test('a field trigger with no field cannot be switched on', function () {
    $workflow = saveWorkflow(workflowData([
        'trigger_event' => WorkflowTrigger::FieldChanged->value,
        'trigger_field' => 'not_a_field',
    ]));

    expect(fn () => app(ToggleWorkflowAction::class)($workflow, true))
        ->toThrow(RuntimeException::class);
});

test('a complete workflow switches on and off', function () {
    $workflow = saveWorkflow(workflowData());

    expect(app(ToggleWorkflowAction::class)($workflow, true)->is_active)->toBeTrue()
        ->and($workflow->fresh()->isRunnable())->toBeTrue()
        ->and(app(ToggleWorkflowAction::class)($workflow->fresh(), false)->is_active)->toBeFalse();
});

test('switching off is never refused', function () {
    // Whatever state a workflow got into, stopping it must always be possible.
    $workflow = saveWorkflow(workflowData(['actions' => []]));
    $workflow->forceFill(['is_active' => true])->save();

    expect(app(ToggleWorkflowAction::class)($workflow, false)->is_active)->toBeFalse();
});

// -- Order ----------------------------------------------------------------------

test('reordering one module leaves the others alone', function () {
    // Renumbering every workflow because somebody dragged one in the leads
    // list is the bug 4.4 had in ReorderPipelinesAction.
    $first = saveWorkflow(workflowData(['name' => 'First']));
    $second = saveWorkflow(workflowData(['name' => 'Second']));
    $deals = saveWorkflow(workflowData(['name' => 'Deals', 'module' => 'deals']));
    $dealsPosition = $deals->position;

    app(ReorderWorkflowsAction::class)('leads', [$second->id, $first->id]);

    expect($second->fresh()->position)->toBe(0)
        ->and($first->fresh()->position)->toBe(1)
        ->and($deals->fresh()->position)->toBe($dealsPosition);
});

test('an id from another module is ignored rather than renumbering it', function () {
    $lead = saveWorkflow(workflowData(['name' => 'Leads one']));
    $deal = saveWorkflow(workflowData(['name' => 'Deals one', 'module' => 'deals']));

    app(ReorderWorkflowsAction::class)('leads', [$deal->id, $lead->id]);

    expect($lead->fresh()->position)->toBe(0)
        ->and($deal->fresh()->position)->toBe(1);
});

test('the engine gets its workflows in order, active ones only', function () {
    $second = saveWorkflow(workflowData(['name' => 'Second']));
    $first = saveWorkflow(workflowData(['name' => 'First']));
    saveWorkflow(workflowData(['name' => 'Dormant']));

    app(ReorderWorkflowsAction::class)('leads', [$first->id, $second->id]);
    app(ToggleWorkflowAction::class)($first->fresh(), true);
    app(ToggleWorkflowAction::class)($second->fresh(), true);

    $listening = Workflow::query()
        ->listeningFor('leads', WorkflowTrigger::RecordCreated)
        ->pluck('name')
        ->all();

    expect($listening)->toBe(['First', 'Second']);
});

// -- The execution log ----------------------------------------------------------

test('a run outlives the workflow it describes', function () {
    // Somebody asking why a record changed last month needs the log to survive
    // the deletion of the thing that changed it.
    $workflow = saveWorkflow(workflowData());
    $lead = Lead::factory()->create();
    $run = WorkflowRun::factory()->forWorkflow($workflow)->about($lead)->create();

    app(DeleteWorkflowAction::class)($workflow);

    $run = $run->fresh();

    expect($run)->not->toBeNull()
        ->and($run->workflow_id)->toBeNull()
        ->and($run->workflow_name)->toBe('Welcome a new lead')
        ->and($run->module)->toBe('leads');
});

test('a step keeps what it was after its action is gone', function () {
    $workflow = saveWorkflow(workflowData([
        'actions' => [
            ['type' => WorkflowActionType::CallWebhook->value, 'config' => ['url' => 'https://example.com/hook']],
        ],
    ]));
    $action = $workflow->actions->first();

    $run = WorkflowRun::factory()->forWorkflow($workflow)->create();
    $step = WorkflowRunStep::factory()->for($run, 'run')->forAction($action)->create();

    saveWorkflow(workflowData(['actions' => []]), $workflow);

    $step = $step->fresh();

    expect($step)->not->toBeNull()
        ->and($step->workflow_action_id)->toBeNull()
        ->and($step->actionType())->toBe(WorkflowActionType::CallWebhook);
});

test('deleting a run takes its steps with it', function () {
    // The steps are part of the run, not history of their own.
    $run = WorkflowRun::factory()->create();
    WorkflowRunStep::factory()->count(2)->for($run, 'run')->create();

    $run->delete();

    expect(WorkflowRunStep::query()->count())->toBe(0);
});

test('the same occasion cannot be logged twice', function () {
    // "Fires exactly once" is a unique index, not a check-then-insert two queue
    // workers can both pass.
    $workflow = saveWorkflow(workflowData());

    WorkflowRun::factory()->forWorkflow($workflow)->create(['dedupe_key' => 'wf:1:lead:7:created']);

    expect(fn () => WorkflowRun::factory()->forWorkflow($workflow)->create(['dedupe_key' => 'wf:1:lead:7:created']))
        ->toThrow(QueryException::class);
});

test('runs without a dedupe key are not constrained by it', function () {
    // A replayed or ad-hoc run makes no claim on an occasion.
    $workflow = saveWorkflow(workflowData());

    WorkflowRun::factory()->count(3)->forWorkflow($workflow)->create(['dedupe_key' => null]);

    expect(WorkflowRun::query()->count())->toBe(3);
});

test('finishing a run does not move the time it started', function () {
    // A NOT NULL timestamp column gets ON UPDATE CURRENT_TIMESTAMP on
    // MySQL/MariaDB, which would silently rewrite started_at on the very UPDATE
    // that finishes the run. See .ai/rules/migrations.md.
    Carbon::setTestNow('2026-10-15 09:00:00');

    $run = WorkflowRun::factory()->create([
        'status' => WorkflowRunStatus::Running->value,
        'started_at' => now(),
        'finished_at' => null,
    ]);

    Carbon::setTestNow('2026-10-15 09:05:00');

    $run->forceFill([
        'status' => WorkflowRunStatus::Success->value,
        'finished_at' => now(),
        'duration_ms' => 300000,
    ])->save();

    expect($run->fresh()->started_at->toDateTimeString())->toBe('2026-10-15 09:00:00');

    Carbon::setTestNow();
});

test('started_at is a datetime column, which is what keeps it still', function () {
    expect(Schema::getColumnType('workflow_runs', 'started_at'))->toBe('datetime');
});

test('a run can be found by the record it was about', function () {
    $lead = Lead::factory()->create();
    $other = Lead::factory()->create();

    WorkflowRun::factory()->about($lead)->create();
    WorkflowRun::factory()->about($other)->create();

    expect(WorkflowRun::query()->forSubject($lead)->count())->toBe(1);
});

test('a scheduled run needs no record at all', function () {
    $run = WorkflowRun::factory()->create([
        'trigger_event' => WorkflowTrigger::Scheduled->value,
        'subject_type' => null,
        'subject_id' => null,
    ]);

    expect($run->subject)->toBeNull()
        ->and($run->trigger()->hasSubject())->toBeFalse();
});

// -- The module registry --------------------------------------------------------

test('every built-in module offers a field set and a model', function (string $module) {
    expect(WorkflowModules::has($module))->toBeTrue()
        ->and(WorkflowModules::fields($module))->not->toBeEmpty()
        ->and(WorkflowModules::modelClass($module))->not->toBeNull()
        ->and(WorkflowModules::morphClass($module))->not->toBeNull()
        ->and(WorkflowModules::label($module))->not->toBe('Unknown');
})->with(fn () => WorkflowModules::builtInKeys());

test('the registry does not answer for a module it does not list', function () {
    expect(WorkflowModules::has('users'))->toBeFalse()
        ->and(WorkflowModules::modelClass('users'))->toBeNull()
        ->and(WorkflowModules::fields('users'))->toBe([])
        ->and(WorkflowModules::label('users'))->toBe('Unknown');
});

test('date field options hold only date fields', function () {
    $dates = WorkflowModules::dateFieldOptions('leads');

    expect($dates)->not->toBeEmpty();

    foreach (array_keys($dates) as $key) {
        expect(WorkflowModules::fields('leads')[$key]->type)->toBe(FilterFieldType::Date);
    }

    expect($dates)->not->toHaveKey('first_name');
});

// -- Permissions ----------------------------------------------------------------

test('the workflow permissions are declared in the catalogue', function (string $permission) {
    expect(PermissionCatalogue::has($permission))->toBeTrue();
})->with(['workflows.view', 'workflows.create', 'workflows.update', 'workflows.delete', 'workflows.logs']);

test('configuring a workflow and reading its log are different permissions', function () {
    // Answering "why did this change last night" is a much wider audience than
    // deciding what happens tonight.
    $reader = User::factory()->create();

    foreach (PermissionResolver::models(['workflows.logs']) as $permission) {
        $reader->givePermissionTo($permission);
    }

    $workflow = saveWorkflow(workflowData());
    $reader = $reader->fresh();

    expect($reader->can('viewLog', Workflow::class))->toBeTrue()
        ->and($reader->can('update', $workflow))->toBeFalse()
        ->and($reader->can('delete', $workflow))->toBeFalse()
        ->and($reader->can('retry', Workflow::class))->toBeFalse();
});

test('a user with no workflow permissions can do nothing with one', function () {
    $user = User::factory()->create();
    $workflow = saveWorkflow(workflowData());

    expect($user->can('viewAny', Workflow::class))->toBeFalse()
        ->and($user->can('create', Workflow::class))->toBeFalse()
        ->and($user->can('update', $workflow))->toBeFalse()
        ->and($user->can('toggle', $workflow))->toBeFalse();
});

// -- The enums ------------------------------------------------------------------

test('every action type declares what it needs and how it reads', function (string $value) {
    $type = WorkflowActionType::from($value);

    expect($type->label())->not->toBeEmpty()
        ->and($type->description())->not->toBeEmpty();

    // Required keys are what lets a step be skipped rather than attempted when
    // it is half set up. Assigning an owner is the one type with nothing fixed
    // to require: what it needs depends on which strategy it uses, and
    // AssignmentResolver reports a clean skip when it cannot choose anybody.
    if ($type !== WorkflowActionType::AssignOwner) {
        expect($type->requiredKeys())->not->toBeEmpty();
    }
})->with(array_column(WorkflowActionType::cases(), 'value'));

test('every run status has a label and a chip colour', function (string $value) {
    $status = WorkflowRunStatus::from($value);

    expect($status->label())->not->toBeEmpty()
        ->and($status->color())->not->toBeEmpty();
})->with(array_column(WorkflowRunStatus::cases(), 'value'));

test('a skipped run is finished but not a failure', function () {
    // A workflow whose conditions did not match did its job. Counting those as
    // failures would bury the real ones in 5.8's health figures.
    expect(WorkflowRunStatus::Skipped->isFinished())->toBeTrue()
        ->and(WorkflowRunStatus::Skipped->isRetryable())->toBeFalse()
        ->and(WorkflowRunStatus::Failed->isRetryable())->toBeTrue()
        ->and(WorkflowRunStatus::Running->isFinished())->toBeFalse();
});

test('a delete trigger is the one whose record does not survive', function () {
    // 5.4 uses this to refuse pairing it with an action that writes to the
    // record — a definition that could never do anything.
    expect(WorkflowTrigger::RecordDeleted->subjectSurvives())->toBeFalse()
        ->and(WorkflowTrigger::RecordUpdated->subjectSurvives())->toBeTrue()
        ->and(WorkflowActionType::UpdateField->writesToSubject())->toBeTrue()
        ->and(WorkflowActionType::CallWebhook->writesToSubject())->toBeFalse();
});

// -- Schema ----------------------------------------------------------------------

test('the workflow tables exist with the columns the engine will need', function () {
    expect(Schema::hasTable('workflows'))->toBeTrue()
        ->and(Schema::hasTable('workflow_actions'))->toBeTrue()
        ->and(Schema::hasTable('workflow_runs'))->toBeTrue()
        ->and(Schema::hasTable('workflow_run_steps'))->toBeTrue();

    expect(Schema::hasColumns('workflows', [
        'name', 'module', 'trigger_event', 'trigger_field', 'date_offset_minutes',
        'conditions', 'is_active', 'position', 'run_once_per_record', 'created_by',
        'schedule_expression',
    ]))->toBeTrue();

    // What a workflow has done lives in workflow_runs. A counter beside the log
    // cost an UPDATE of one row on every event and raced with itself; 5.2
    // dropped the pair.
    expect(Schema::hasColumn('workflows', 'run_count'))->toBeFalse()
        ->and(Schema::hasColumn('workflows', 'last_run_at'))->toBeFalse();

    // `trigger` is a reserved word in MySQL, so the column is `trigger_event`.
    expect(Schema::hasColumn('workflows', 'trigger'))->toBeFalse();
});

test('the workflow tables roll back cleanly and re-migrate cleanly', function () {
    // The migration's own down() and up(), rather than `migrate:rollback
    // --step`, whose step count drifts the moment a later migration lands.
    // Foreign keys are genuinely enforced against MySQL, so a down() that drops
    // in the wrong order fails here rather than in production.
    $migration = require database_path('migrations/2026_09_11_111355_create_workflow_tables.php');

    Schema::withoutForeignKeyConstraints(function () use ($migration) {
        $migration->down();

        expect(Schema::hasTable('workflow_run_steps'))->toBeFalse()
            ->and(Schema::hasTable('workflow_runs'))->toBeFalse()
            ->and(Schema::hasTable('workflow_actions'))->toBeFalse()
            ->and(Schema::hasTable('workflows'))->toBeFalse();

        $migration->up();
    });

    expect(Schema::hasTable('workflows'))->toBeTrue()
        ->and(Schema::hasTable('workflow_run_steps'))->toBeTrue();
});
