<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Workflows\Actions\RunDateWorkflowsAction;
use App\Domain\Workflows\Actions\RunScheduledWorkflowsAction;
use App\Domain\Workflows\Actions\SaveWorkflowAction;
use App\Domain\Workflows\Actions\ToggleWorkflowAction;
use App\Domain\Workflows\DTOs\WorkflowData;
use App\Domain\Workflows\Enums\WorkflowActionType;
use App\Domain\Workflows\Enums\WorkflowRunStatus;
use App\Domain\Workflows\Enums\WorkflowTrigger;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Triggers\WorkflowSuppressor;
use App\Domain\Workflows\WorkflowCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A live workflow on leads, watching whichever trigger the test is about.
 *
 * Saved through the real action and switched on by writing the column, because
 * ToggleWorkflowAction's guards are 5.1's subject and this file is about what
 * fires.
 *
 * @param  array<string, mixed>  $overrides
 */
function liveWorkflow(array $overrides = []): Workflow
{
    $workflow = app(SaveWorkflowAction::class)(WorkflowData::fromArray([
        'name' => 'Under test',
        'module' => 'leads',
        'trigger_event' => WorkflowTrigger::RecordCreated->value,
        'actions' => [
            ['type' => WorkflowActionType::UpdateField->value, 'config' => ['field' => 'status', 'value' => 'contacted']],
        ],
        ...$overrides,
    ]));

    $workflow->forceFill(['is_active' => true])->save();

    app(WorkflowCache::class)->flush();

    return $workflow->fresh();
}

beforeEach(function () {
    // The listening set is memoised per request, and a test is one "request"
    // that both edits workflows and saves records under them.
    app(WorkflowCache::class)->flush();
});

// -- Each trigger type, exactly once -------------------------------------------

test('creating a record fires a create workflow exactly once', function () {
    $workflow = liveWorkflow();

    $lead = Lead::factory()->create();

    $runs = WorkflowRun::query()->get();

    expect($runs)->toHaveCount(1)
        ->and($runs->first()->workflow_id)->toBe($workflow->id)
        ->and($runs->first()->subject_id)->toBe($lead->id)
        ->and($runs->first()->trigger())->toBe(WorkflowTrigger::RecordCreated)
        // Carried out rather than left pending: the run is queued the moment it
        // is claimed, and the queue is synchronous here.
        ->and($runs->first()->status())->toBe(WorkflowRunStatus::Success);
});

test('updating a record fires an update workflow exactly once', function () {
    liveWorkflow(['trigger_event' => WorkflowTrigger::RecordUpdated->value]);

    $lead = Lead::factory()->create();

    expect(WorkflowRun::query()->count())->toBe(0);

    $lead->update(['first_name' => 'Priya']);

    expect(WorkflowRun::query()->count())->toBe(1);
});

test('deleting a record fires a delete workflow exactly once', function () {
    liveWorkflow(['trigger_event' => WorkflowTrigger::RecordDeleted->value]);

    $lead = Lead::factory()->create();
    $lead->delete();

    $run = WorkflowRun::query()->sole();

    expect($run->trigger())->toBe(WorkflowTrigger::RecordDeleted)
        ->and($run->subject_id)->toBe($lead->id);
});

test('a field change fires only for the field the workflow watches', function () {
    liveWorkflow([
        'trigger_event' => WorkflowTrigger::FieldChanged->value,
        'trigger_field' => 'status',
    ]);

    $lead = Lead::factory()->create();

    // A change to something else is not this workflow's business.
    $lead->update(['first_name' => 'Priya']);

    expect(WorkflowRun::query()->count())->toBe(0);

    $lead->update(['status' => LeadStatus::Contacted->value]);

    $run = WorkflowRun::query()->sole();

    expect($run->trigger())->toBe(WorkflowTrigger::FieldChanged)
        // What changed is recorded, so 5.4 can act on the old value and the
        // log says why the workflow fired.
        ->and($run->context['changed']['status']['from'])->toBe(LeadStatus::New->value)
        ->and($run->context['changed']['status']['to'])->toBe(LeadStatus::Contacted->value);
});

test('one edit fires the update and field-change workflows once each', function () {
    // They are separate triggers with separate workflows behind them, so an
    // edit reaching both is right — what would be wrong is either firing twice.
    liveWorkflow(['name' => 'On any update', 'trigger_event' => WorkflowTrigger::RecordUpdated->value]);
    liveWorkflow([
        'name' => 'On status',
        'trigger_event' => WorkflowTrigger::FieldChanged->value,
        'trigger_field' => 'status',
    ]);

    $lead = Lead::factory()->create();
    $lead->update(['status' => LeadStatus::Contacted->value]);

    expect(WorkflowRun::query()->count())->toBe(2)
        ->and(WorkflowRun::query()->where('workflow_name', 'On any update')->count())->toBe(1)
        ->and(WorkflowRun::query()->where('workflow_name', 'On status')->count())->toBe(1);
});

test('a date workflow fires once per record, however often the sweep runs', function () {
    Carbon::setTestNow('2026-10-15 09:00:00');

    $workflow = liveWorkflow([
        'trigger_event' => WorkflowTrigger::DateReached->value,
        'trigger_field' => 'created_at',
        // Three days after the lead was captured.
        'date_offset_minutes' => 3 * 24 * 60,
    ]);

    $lead = Lead::factory()->create();

    // Nothing yet: the moment is three days away.
    expect(app(RunDateWorkflowsAction::class)())->toBe(0);

    Carbon::setTestNow('2026-10-18 09:01:00');

    expect(app(RunDateWorkflowsAction::class)())->toBe(1);

    // The sweep runs every minute forever. It must not fire again.
    expect(app(RunDateWorkflowsAction::class)())->toBe(0)
        ->and(app(RunDateWorkflowsAction::class)())->toBe(0)
        ->and(WorkflowRun::query()->count())->toBe(1);

    expect(WorkflowRun::query()->sole()->subject_id)->toBe($lead->id);

    Carbon::setTestNow();
});

test('a date workflow does not fire for records that predate it', function () {
    // Otherwise switching on "three days after capture" would, on its first
    // sweep, fire for every lead ever captured.
    Carbon::setTestNow('2026-10-15 09:00:00');
    $old = Lead::factory()->create();

    Carbon::setTestNow('2026-10-20 09:00:00');
    liveWorkflow([
        'trigger_event' => WorkflowTrigger::DateReached->value,
        'trigger_field' => 'created_at',
        'date_offset_minutes' => 0,
    ]);

    Carbon::setTestNow('2026-10-21 09:00:00');
    $new = Lead::factory()->create();

    Carbon::setTestNow('2026-10-21 09:05:00');

    expect(app(RunDateWorkflowsAction::class)())->toBe(1)
        ->and(WorkflowRun::query()->sole()->subject_id)->toBe($new->id);

    expect(WorkflowRun::query()->where('subject_id', $old->id)->exists())->toBeFalse();

    Carbon::setTestNow();
});

test('a negative offset fires before the date, not after', function () {
    Carbon::setTestNow('2026-10-15 09:00:00');

    liveWorkflow([
        'trigger_event' => WorkflowTrigger::DateReached->value,
        'trigger_field' => 'status_changed_at',
        // An hour before the moment.
        'date_offset_minutes' => -60,
    ]);

    $lead = Lead::factory()->create(['status_changed_at' => Carbon::parse('2026-10-15 12:00:00')]);

    // Two hours ahead of the date: too early even with the hour's lead time.
    Carbon::setTestNow('2026-10-15 10:00:00');
    expect(app(RunDateWorkflowsAction::class)())->toBe(0);

    // One hour ahead: due.
    Carbon::setTestNow('2026-10-15 11:00:00');
    expect(app(RunDateWorkflowsAction::class)())->toBe(1)
        ->and(WorkflowRun::query()->sole()->subject_id)->toBe($lead->id);

    Carbon::setTestNow();
});

test('a scheduled workflow fires once per due minute', function () {
    Carbon::setTestNow('2026-10-15 08:59:00');

    liveWorkflow([
        'trigger_event' => WorkflowTrigger::Scheduled->value,
        // Every day at nine.
        'schedule_expression' => '0 9 * * *',
    ]);

    expect(app(RunScheduledWorkflowsAction::class)())->toBe(0);

    Carbon::setTestNow('2026-10-15 09:00:00');

    expect(app(RunScheduledWorkflowsAction::class)())->toBe(1);

    // The sweep runs every minute; within the same minute it must not fire
    // again.
    expect(app(RunScheduledWorkflowsAction::class)())->toBe(0)
        ->and(WorkflowRun::query()->count())->toBe(1);

    // The next day is a new occasion.
    Carbon::setTestNow('2026-10-16 09:00:00');

    expect(app(RunScheduledWorkflowsAction::class)())->toBe(1)
        ->and(WorkflowRun::query()->count())->toBe(2);

    Carbon::setTestNow();
});

test('a scheduled run is about no record at all', function () {
    Carbon::setTestNow('2026-10-15 09:00:00');

    liveWorkflow([
        'trigger_event' => WorkflowTrigger::Scheduled->value,
        'schedule_expression' => '0 9 * * *',
    ]);

    app(RunScheduledWorkflowsAction::class)();

    $run = WorkflowRun::query()->sole();

    expect($run->subject_type)->toBeNull()
        ->and($run->subject_id)->toBeNull()
        ->and($run->context['schedule'])->toBe('0 9 * * *');

    Carbon::setTestNow();
});

// -- What must not fire ---------------------------------------------------------

test('a workflow that is off fires nothing', function () {
    $workflow = liveWorkflow();
    $workflow->forceFill(['is_active' => false])->save();
    app(WorkflowCache::class)->flush();

    Lead::factory()->create();

    expect(WorkflowRun::query()->count())->toBe(0);
});

test('a workflow with no active step fires nothing', function () {
    // Somebody part way through writing one must not have it running.
    $workflow = liveWorkflow();
    $workflow->actions()->update(['is_active' => false]);
    app(WorkflowCache::class)->flush();

    Lead::factory()->create();

    expect(WorkflowRun::query()->count())->toBe(0);
});

test('a workflow on one module ignores another module', function () {
    liveWorkflow();

    Contact::factory()->create();

    expect(WorkflowRun::query()->count())->toBe(0);
});

test('an update that changes nothing fires nothing', function () {
    liveWorkflow(['trigger_event' => WorkflowTrigger::RecordUpdated->value]);

    $lead = Lead::factory()->create();
    $lead->update(['first_name' => $lead->first_name]);

    expect(WorkflowRun::query()->count())->toBe(0);
});

test('a run once per record workflow fires only the first time', function () {
    liveWorkflow([
        'trigger_event' => WorkflowTrigger::RecordUpdated->value,
        'run_once_per_record' => true,
    ]);

    $lead = Lead::factory()->create();
    $lead->update(['first_name' => 'One']);
    $lead->update(['first_name' => 'Two']);
    $lead->update(['first_name' => 'Three']);

    expect(WorkflowRun::query()->count())->toBe(1);
});

test('the same record edited twice fires an update workflow twice', function () {
    // The counterpart to the test above: without run_once_per_record, each edit
    // is its own occasion and there is nothing to dedupe.
    liveWorkflow(['trigger_event' => WorkflowTrigger::RecordUpdated->value]);

    $lead = Lead::factory()->create();
    $lead->update(['first_name' => 'One']);
    $lead->update(['first_name' => 'Two']);

    expect(WorkflowRun::query()->count())->toBe(2);
});

// -- The recursion guard --------------------------------------------------------

test('a write made by a workflow raises no triggers', function () {
    // The loop 5.4 would otherwise create: an action sets a field, the update
    // trigger fires, the workflow runs, the action sets the field.
    liveWorkflow(['trigger_event' => WorkflowTrigger::RecordUpdated->value]);

    $lead = Lead::factory()->create();

    app(WorkflowSuppressor::class)->while(function () use ($lead) {
        $lead->update(['first_name' => 'Set by an action']);
    });

    expect(WorkflowRun::query()->count())->toBe(0);

    // And triggers are armed again afterwards.
    $lead->update(['first_name' => 'Set by a person']);

    expect(WorkflowRun::query()->count())->toBe(1);
});

test('a throwing action still leaves triggers armed', function () {
    liveWorkflow(['trigger_event' => WorkflowTrigger::RecordUpdated->value]);
    $lead = Lead::factory()->create();

    try {
        app(WorkflowSuppressor::class)->while(function () {
            throw new RuntimeException('The action failed.');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    $lead->update(['first_name' => 'Priya']);

    expect(WorkflowRun::query()->count())->toBe(1);
});

test('nested suppression does not re-arm triggers early', function () {
    $suppressor = app(WorkflowSuppressor::class);

    $suppressor->while(function () use ($suppressor) {
        $suppressor->while(fn () => null);

        // The inner call has returned; the outer is still writing.
        expect($suppressor->isSuppressed())->toBeTrue();
    });

    expect($suppressor->isSuppressed())->toBeFalse();
});

// -- Transactions ----------------------------------------------------------------

test('a record that was rolled back fires nothing', function () {
    // Lead conversion writes an account, a contact and a deal in one
    // transaction. A workflow that fired before the commit would be acting on a
    // record nobody can look at.
    liveWorkflow();

    try {
        DB::transaction(function () {
            Lead::factory()->create();

            throw new RuntimeException('Rolled back.');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    expect(Lead::query()->count())->toBe(0)
        ->and(WorkflowRun::query()->count())->toBe(0);
});

// -- Cost -----------------------------------------------------------------------

test('saving many records does not query for workflows each time', function () {
    // Without the request-scoped memo, an import of five thousand leads is five
    // thousand identical lookups.
    liveWorkflow();

    DB::flushQueryLog();
    DB::enableQueryLog();

    Lead::factory()->count(5)->create();

    // The listening lookup specifically — carrying out a run reads its own
    // workflow back, which is inherent: the job carries an id so that it reads
    // the current state rather than a serialised copy.
    $lookups = collect(DB::getRawQueryLog())
        ->filter(fn (array $entry): bool => str_contains($entry['raw_query'], 'from `workflows`')
            && str_contains($entry['raw_query'], 'trigger_event'))
        ->count();

    DB::disableQueryLog();

    // One, and that answer is then remembered for the other four.
    expect($lookups)->toBe(1);
});

test('switching a workflow on is seen by the very next save', function () {
    // The other half of the memo: a cache nobody flushes is how a workflow
    // somebody just switched on does not run until the next deploy.
    $workflow = liveWorkflow();
    $workflow->forceFill(['is_active' => false])->save();
    app(WorkflowCache::class)->flush();

    Lead::factory()->create();
    expect(WorkflowRun::query()->count())->toBe(0);

    app(ToggleWorkflowAction::class)($workflow->fresh(), true);

    Lead::factory()->create();
    expect(WorkflowRun::query()->count())->toBe(1);
});

// -- Bookkeeping -----------------------------------------------------------------

test('a firing writes nothing back to the workflow', function () {
    // What a workflow has done is in the log. A counter beside it would be an
    // UPDATE of one row on every event, contended by every concurrent insert,
    // and `n + 1` read in PHP is a race two workers both lose.
    Carbon::setTestNow('2026-10-15 09:00:00');

    $workflow = liveWorkflow(['trigger_event' => WorkflowTrigger::RecordUpdated->value]);
    $touched = $workflow->updated_at;

    $lead = Lead::factory()->create();
    $lead->update(['first_name' => 'One']);
    $lead->update(['first_name' => 'Two']);

    expect($workflow->fresh()->updated_at->eq($touched))->toBeTrue()
        // And the log holds what the counter would have said.
        ->and(WorkflowRun::query()->where('workflow_id', $workflow->id)->count())->toBe(2);

    Carbon::setTestNow();
});

test('a run records the workflow name as it was when it fired', function () {
    $workflow = liveWorkflow();

    Lead::factory()->create();

    $workflow->forceFill(['name' => 'Renamed afterwards'])->save();

    expect(WorkflowRun::query()->sole()->workflow_name)->toBe('Under test');
});
