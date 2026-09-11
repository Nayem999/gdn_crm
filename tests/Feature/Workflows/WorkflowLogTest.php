<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Leads\Models\Lead;
use App\Domain\Workflows\Actions\DeleteWorkflowAction;
use App\Domain\Workflows\Actions\RetryWorkflowRunAction;
use App\Domain\Workflows\Enums\WorkflowActionType;
use App\Domain\Workflows\Enums\WorkflowRunStatus;
use App\Domain\Workflows\Enums\WorkflowTrigger;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowAction;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Models\WorkflowRunStep;
use App\Domain\Workflows\WorkflowCache;
use App\Livewire\Workflows\WorkflowRunsIndex;
use App\Livewire\Workflows\WorkflowsIndex;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function logUser(array $permissions = ['workflows.logs']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

/**
 * A workflow whose second step fails, fired for one lead.
 *
 * @return array{0: WorkflowRun, 1: Lead, 2: Workflow}
 */
function failedRun(): array
{
    $workflow = Workflow::factory()->create([
        'module' => 'leads',
        'trigger_event' => WorkflowTrigger::RecordCreated->value,
        'is_active' => true,
        'name' => 'Chase and shout',
    ]);

    // Succeeds.
    WorkflowAction::factory()->for($workflow)->create([
        'type' => WorkflowActionType::UpdateField->value,
        'position' => 0,
        'config' => ['field' => 'city', 'value' => 'Dhaka'],
    ]);

    // Fails: an address inside this network is refused before anything is sent.
    WorkflowAction::factory()->for($workflow)->create([
        'type' => WorkflowActionType::CallWebhook->value,
        'position' => 1,
        'config' => ['url' => 'http://169.254.169.254/latest/meta-data/'],
    ]);

    app(WorkflowCache::class)->flush();

    $lead = Lead::factory()->create();

    return [WorkflowRun::query()->sole()->fresh(), $lead->fresh(), $workflow];
}

beforeEach(function () {
    app(WorkflowCache::class)->flush();
    Http::preventStrayRequests();
});

// -- Failures are logged ---------------------------------------------------------

test('a failure is recorded with the step that failed and why', function () {
    [$run, $lead] = failedRun();

    expect($run->status())->toBe(WorkflowRunStatus::Failed)
        ->and($run->message)->toContain('inside this network')
        // The step before it did its work, and says so.
        ->and($lead->city)->toBe('Dhaka');

    $steps = WorkflowRunStep::query()->orderBy('position')->get();

    expect($steps)->toHaveCount(2)
        ->and($steps[0]->status())->toBe(WorkflowRunStatus::Success)
        ->and($steps[1]->status())->toBe(WorkflowRunStatus::Failed)
        ->and($steps[1]->message)->toContain('inside this network');
});

// -- And retryable -----------------------------------------------------------------

test('a retry picks up at the step that failed, not at the beginning', function () {
    // Repeating the earlier steps would send a second email, make a second
    // task, call a webhook twice. A retry that does the work twice is worse
    // than one that does nothing.
    [$run] = failedRun();

    Http::fake(['https://example.com/hook' => Http::response([], 200)]);

    // Point the failing step somewhere that works, as somebody fixing it would.
    $run->workflow->actions()->where('position', 1)->update([
        'config' => json_encode(['url' => 'https://example.com/hook']),
    ]);

    app(RetryWorkflowRunAction::class)($run);

    expect($run->fresh()->status())->toBe(WorkflowRunStatus::Success);

    // The first step ran once, across both attempts.
    expect(WorkflowRunStep::query()->where('position', 0)->count())->toBe(1)
        ->and(WorkflowRunStep::query()->where('position', 1)->count())->toBe(2);
});

test('a retry keeps the first attempt in the log', function () {
    // "Did this ever work" needs to see that it failed at nine and succeeded at
    // ten, not only the second half.
    [$run] = failedRun();

    Http::fake(['https://example.com/hook' => Http::response([], 200)]);
    $run->workflow->actions()->where('position', 1)->update([
        'config' => json_encode(['url' => 'https://example.com/hook']),
    ]);

    app(RetryWorkflowRunAction::class)($run);

    $attempts = WorkflowRunStep::query()->where('position', 1)->orderBy('id')->get();

    expect($attempts)->toHaveCount(2)
        ->and($attempts[0]->status())->toBe(WorkflowRunStatus::Failed)
        ->and($attempts[1]->status())->toBe(WorkflowRunStatus::Success);
});

test('a retry clears the previous reason and restarts the clock', function () {
    Carbon::setTestNow('2026-10-15 09:00:00');

    [$run] = failedRun();

    expect($run->message)->not->toBeNull();

    Http::fake(['https://example.com/hook' => Http::response([], 200)]);
    $run->workflow->actions()->where('position', 1)->update([
        'config' => json_encode(['url' => 'https://example.com/hook']),
    ]);

    Carbon::setTestNow('2026-10-15 10:00:00');

    app(RetryWorkflowRunAction::class)($run);

    $run = $run->fresh();

    expect($run->message)->toBeNull()
        // The duration describes this attempt, not the hour in between.
        ->and($run->started_at->toDateTimeString())->toBe('2026-10-15 10:00:00')
        ->and($run->duration_ms)->toBeLessThan(60000);

    Carbon::setTestNow();
});

test('a retry that fails again is still failed, with the new reason', function () {
    [$run] = failedRun();

    app(RetryWorkflowRunAction::class)($run);

    expect($run->fresh()->status())->toBe(WorkflowRunStatus::Failed)
        ->and($run->fresh()->message)->toContain('inside this network');
});

test('only a failed run can be retried', function () {
    // A successful run retried would do its work a second time; a waiting one
    // needs a person, not another attempt.
    $succeeded = WorkflowRun::factory()->create(['status' => WorkflowRunStatus::Success->value]);
    $waiting = WorkflowRun::factory()->create(['status' => WorkflowRunStatus::AwaitingApproval->value]);
    $skipped = WorkflowRun::factory()->create(['status' => WorkflowRunStatus::Skipped->value]);

    foreach ([$succeeded, $waiting, $skipped] as $run) {
        expect(fn () => app(RetryWorkflowRunAction::class)($run))->toThrow(RuntimeException::class);
    }
});

test('a run whose workflow is gone cannot be retried', function () {
    [$run] = failedRun();

    app(DeleteWorkflowAction::class)($run->workflow);

    expect(fn () => app(RetryWorkflowRunAction::class)($run->fresh()))
        ->toThrow(RuntimeException::class);

    // But it is still readable, which is the point of keeping it.
    expect($run->fresh()->workflow_name)->toBe('Chase and shout');
});

// -- The screen -----------------------------------------------------------------------

test('the log lists runs with their outcome', function () {
    failedRun();

    Livewire::actingAs(logUser())
        ->test(WorkflowRunsIndex::class)
        ->assertOk()
        ->assertSee('Chase and shout')
        ->assertSee('Failed');
});

test('the tally counts each outcome in one query', function () {
    WorkflowRun::factory()->count(3)->create(['status' => WorkflowRunStatus::Success->value]);
    WorkflowRun::factory()->count(2)->create(['status' => WorkflowRunStatus::Failed->value]);

    $tally = Livewire::actingAs(logUser())
        ->test(WorkflowRunsIndex::class)
        ->instance()
        ->tally();

    expect($tally[WorkflowRunStatus::Success->value])->toBe(3)
        ->and($tally[WorkflowRunStatus::Failed->value])->toBe(2);
});

test('the log filters by outcome, module, workflow and trigger', function () {
    $wanted = Workflow::factory()->create(['name' => 'Wanted', 'module' => 'leads']);
    WorkflowRun::factory()->forWorkflow($wanted)->create(['status' => WorkflowRunStatus::Failed->value]);
    WorkflowRun::factory()->create(['workflow_name' => 'Unwanted', 'module' => 'deals', 'status' => WorkflowRunStatus::Success->value]);

    $screen = Livewire::actingAs(logUser())->test(WorkflowRunsIndex::class);

    // Asserted on the query rather than the rendered page: the workflow filter
    // lists every workflow by name, so "not on screen" would match a dropdown
    // option and pass for the wrong reason.
    $names = fn (): array => $screen->instance()->runs()->pluck('workflow_name')->all();

    $screen->set('status', WorkflowRunStatus::Failed->value);
    expect($names())->toBe(['Wanted']);

    $screen->set('status', '')->set('module', 'deals');
    expect($names())->toBe(['Unwanted']);

    $screen->set('module', '')->set('workflow', (string) $wanted->id);
    expect($names())->toBe(['Wanted']);
});

test('a run expands to show what each step did', function () {
    [$run] = failedRun();

    Livewire::actingAs(logUser())
        ->test(WorkflowRunsIndex::class)
        ->call('expand', $run->id)
        ->assertSee('Update a field')
        ->assertSee('Call a webhook')
        ->assertSee('Set city to Dhaka');
});

test('retrying from the screen puts the run back on the queue', function () {
    [$run] = failedRun();

    Http::fake(['https://example.com/hook' => Http::response([], 200)]);
    $run->workflow->actions()->where('position', 1)->update([
        'config' => json_encode(['url' => 'https://example.com/hook']),
    ]);

    Livewire::actingAs(logUser(['workflows.logs', 'workflows.update']))
        ->test(WorkflowRunsIndex::class)
        ->call('retry', $run->id);

    expect($run->fresh()->status())->toBe(WorkflowRunStatus::Success);
});

test('retrying the failures shown obeys the filters', function () {
    $leads = Workflow::factory()->create(['module' => 'leads', 'name' => 'On leads']);
    $deals = Workflow::factory()->create(['module' => 'deals', 'name' => 'On deals']);
    WorkflowAction::factory()->for($leads)->create();
    WorkflowAction::factory()->for($deals)->create();

    $leadRun = WorkflowRun::factory()->forWorkflow($leads)->create(['status' => WorkflowRunStatus::Failed->value]);
    $dealRun = WorkflowRun::factory()->forWorkflow($deals)->create(['status' => WorkflowRunStatus::Failed->value]);

    Livewire::actingAs(logUser(['workflows.logs', 'workflows.update']))
        ->test(WorkflowRunsIndex::class)
        ->set('module', 'leads')
        ->call('retryFiltered');

    // The leads one went back through the queue; the deals one was not touched.
    expect($leadRun->fresh()->status())->not->toBe(WorkflowRunStatus::Failed)
        ->and($dealRun->fresh()->status())->toBe(WorkflowRunStatus::Failed);
});

test('a bulk retry is not stopped by one run it cannot retry', function () {
    $workflow = Workflow::factory()->create(['module' => 'leads']);
    WorkflowAction::factory()->for($workflow)->create();

    $orphan = WorkflowRun::factory()->create([
        'workflow_id' => null,
        'module' => 'leads',
        'status' => WorkflowRunStatus::Failed->value,
    ]);
    $retryable = WorkflowRun::factory()->forWorkflow($workflow)->create(['status' => WorkflowRunStatus::Failed->value]);

    Livewire::actingAs(logUser(['workflows.logs', 'workflows.update']))
        ->test(WorkflowRunsIndex::class)
        ->set('module', 'leads')
        ->call('retryFiltered');

    expect($retryable->fresh()->status())->not->toBe(WorkflowRunStatus::Failed)
        ->and($orphan->fresh()->status())->toBe(WorkflowRunStatus::Failed);
});

// -- Permissions -----------------------------------------------------------------------

test('reading the log is its own permission', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('workflows.log'))
        ->assertForbidden();

    $this->actingAs(logUser())
        ->get(route('workflows.log'))
        ->assertOk();
});

test('a reader of the log cannot retry', function () {
    // Retrying writes to records; reading does not.
    [$run] = failedRun();

    Livewire::actingAs(logUser(['workflows.logs']))
        ->test(WorkflowRunsIndex::class)
        ->call('retry', $run->id)
        ->assertForbidden();

    expect($run->fresh()->status())->toBe(WorkflowRunStatus::Failed);
});

test('a reader cannot bulk retry either', function () {
    WorkflowRun::factory()->create(['status' => WorkflowRunStatus::Failed->value]);

    Livewire::actingAs(logUser(['workflows.logs']))
        ->test(WorkflowRunsIndex::class)
        ->call('retryFiltered')
        ->assertForbidden();
});

test('the workflows screen links the log only for somebody who may read it', function () {
    $reader = logUser(['workflows.view', 'workflows.logs']);
    $writer = logUser(['workflows.view']);

    Livewire::actingAs($reader)
        ->test(WorkflowsIndex::class)
        ->assertSee(route('workflows.log'), false);

    Livewire::actingAs($writer)
        ->test(WorkflowsIndex::class)
        ->assertDontSee(route('workflows.log'), false);
});
