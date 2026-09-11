<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Approvals\Actions\DecideApprovalAction;
use App\Domain\Approvals\Actions\EscalateApprovalsAction;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Enums\EscalationOutcome;
use App\Domain\Approvals\Models\ApprovalLevel;
use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Leads\Models\Lead;
use App\Domain\Workflows\Actions\DeleteWorkflowAction;
use App\Domain\Workflows\Enums\WorkflowActionType;
use App\Domain\Workflows\Enums\WorkflowRunStatus;
use App\Domain\Workflows\Enums\WorkflowTrigger;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowAction;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Models\WorkflowRunStep;
use App\Domain\Workflows\WorkflowCache;
use App\Livewire\Approvals\ApprovalsIndex;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * A live workflow that asks for approval and then sets a field, plus the lead
 * that fires it.
 *
 * @param  array<int, User>  $approvers
 * @return array{0: WorkflowRun, 1: Lead, 2: ApprovalRequest}
 */
function fireApproval(array $approvers, array $approvalConfig = []): array
{
    $workflow = Workflow::factory()->create([
        'module' => 'leads',
        'trigger_event' => WorkflowTrigger::RecordCreated->value,
        'is_active' => true,
    ]);

    WorkflowAction::factory()->for($workflow)->create([
        'type' => WorkflowActionType::RequestApproval->value,
        'position' => 0,
        'config' => [
            'approvers' => array_map(fn (User $user): string => 'user:'.$user->id, $approvers),
            ...$approvalConfig,
        ],
    ]);

    // The step the approval gates. It must not run until somebody says yes.
    WorkflowAction::factory()->for($workflow)->create([
        'type' => WorkflowActionType::UpdateField->value,
        'position' => 1,
        'config' => ['field' => 'company_name', 'value' => 'Approved'],
    ]);

    app(WorkflowCache::class)->flush();

    $lead = Lead::factory()->create();

    return [WorkflowRun::query()->sole()->fresh(), $lead->fresh(), ApprovalRequest::query()->sole()];
}

beforeEach(function () {
    app(WorkflowCache::class)->flush();
});

// -- The approve path ---------------------------------------------------------------

test('an approval stops the workflow before the steps it gates', function () {
    $approver = User::factory()->create();

    [$run, $lead, $request] = fireApproval([$approver]);

    expect($run->status())->toBe(WorkflowRunStatus::AwaitingApproval)
        // The gated step has not run.
        ->and($lead->company_name)->not->toBe('Approved')
        ->and($request->status())->toBe(ApprovalStatus::Waiting)
        // And the run knows where to pick up.
        ->and($run->resume_from_position)->toBe(1);

    // Not finished, so the timing figures are not polluted by days of waiting.
    expect($run->finished_at)->toBeNull()
        ->and($run->duration_ms)->toBeNull();
});

test('approving lets the rest of the workflow run', function () {
    $approver = User::factory()->create();

    [$run, $lead, $request] = fireApproval([$approver]);

    app(DecideApprovalAction::class)($request, $approver, true, 'Fine by me.');

    expect($lead->fresh()->company_name)->toBe('Approved')
        ->and($run->fresh()->status())->toBe(WorkflowRunStatus::Success)
        ->and($request->fresh()->status())->toBe(ApprovalStatus::Approved)
        ->and($request->fresh()->levels->first()->comment)->toBe('Fine by me.')
        ->and($request->fresh()->levels->first()->decided_by)->toBe($approver->id);
});

test('a resumed run does not repeat the steps that already ran', function () {
    $approver = User::factory()->create();
    $workflow = Workflow::factory()->create(['module' => 'leads', 'is_active' => true]);

    // A step before the approval, which must run once and only once.
    WorkflowAction::factory()->for($workflow)->create([
        'type' => WorkflowActionType::UpdateField->value,
        'position' => 0,
        'config' => ['field' => 'city', 'value' => 'Dhaka'],
    ]);
    WorkflowAction::factory()->for($workflow)->create([
        'type' => WorkflowActionType::RequestApproval->value,
        'position' => 1,
        'config' => ['approvers' => ['user:'.$approver->id]],
    ]);
    WorkflowAction::factory()->for($workflow)->create([
        'type' => WorkflowActionType::UpdateField->value,
        'position' => 2,
        'config' => ['field' => 'company_name', 'value' => 'Approved'],
    ]);

    app(WorkflowCache::class)->flush();
    Lead::factory()->create();

    $request = ApprovalRequest::query()->sole();

    app(DecideApprovalAction::class)($request, $approver, true);

    // Three steps, each recorded once: the first did not run again on resume.
    expect(WorkflowRunStep::query()->count())->toBe(3)
        ->and(WorkflowRunStep::query()->where('position', 0)->count())->toBe(1);
});

// -- The reject path -----------------------------------------------------------------

test('rejecting stops the workflow and the steps it gated never run', function () {
    $approver = User::factory()->create();

    [$run, $lead, $request] = fireApproval([$approver]);

    app(DecideApprovalAction::class)($request, $approver, false, 'Too big a discount.');

    expect($lead->fresh()->company_name)->not->toBe('Approved')
        ->and($run->fresh()->status())->toBe(WorkflowRunStatus::Rejected)
        ->and($request->fresh()->status())->toBe(ApprovalStatus::Rejected)
        ->and($request->fresh()->decision_comment)->toBe('Too big a discount.');
});

test('a rejection is not counted as a failure', function () {
    // Somebody considered it and said no. The workflow stopping is it working.
    $approver = User::factory()->create();
    [$run, , $request] = fireApproval([$approver]);

    app(DecideApprovalAction::class)($request, $approver, false);

    $status = $run->fresh()->status();

    expect($status)->toBe(WorkflowRunStatus::Rejected)
        ->and($status->isFinished())->toBeTrue()
        ->and($status->isRetryable())->toBeFalse();
});

// -- Multi-step chains -----------------------------------------------------------------

test('each approver is asked in turn, not all at once', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    [, , $request] = fireApproval([$first, $second]);

    // Only the first is being asked.
    expect($request->isAwaiting($first->id))->toBeTrue()
        ->and($request->isAwaiting($second->id))->toBeFalse();

    app(DecideApprovalAction::class)($request, $first, true);

    $request = $request->fresh();

    // Now the second, and the workflow is still waiting.
    expect($request->status())->toBe(ApprovalStatus::Waiting)
        ->and($request->isAwaiting($second->id))->toBeTrue()
        ->and($request->run->status())->toBe(WorkflowRunStatus::AwaitingApproval);

    app(DecideApprovalAction::class)($request, $second, true);

    expect($request->fresh()->status())->toBe(ApprovalStatus::Approved)
        ->and($request->fresh()->run->status())->toBe(WorkflowRunStatus::Success);
});

test('somebody further down the chain cannot answer early', function () {
    // Answering out of turn would skip the people whose agreement the chain
    // exists to collect.
    $first = User::factory()->create();
    $second = User::factory()->create();

    [, , $request] = fireApproval([$first, $second]);

    expect(fn () => app(DecideApprovalAction::class)($request, $second, true))
        ->toThrow(RuntimeException::class);

    expect($request->fresh()->status())->toBe(ApprovalStatus::Waiting);
});

test('a rejection anywhere in the chain ends it', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    [, , $request] = fireApproval([$first, $second]);

    app(DecideApprovalAction::class)($request, $first, false);

    expect($request->fresh()->status())->toBe(ApprovalStatus::Rejected)
        // The person who was never reached is not left waiting on a list.
        ->and($request->fresh()->levels->last()->status())->toBe(ApprovalStatus::Cancelled);
});

test('a settled approval cannot be answered again', function () {
    $approver = User::factory()->create();
    [, , $request] = fireApproval([$approver]);

    app(DecideApprovalAction::class)($request, $approver, true);

    expect(fn () => app(DecideApprovalAction::class)($request->fresh(), $approver, false))
        ->toThrow(RuntimeException::class);
});

// -- Escalation on timeout -----------------------------------------------------------

test('an unanswered level passes to the next person', function () {
    Carbon::setTestNow('2026-10-15 09:00:00');

    $first = User::factory()->create();
    $second = User::factory()->create();

    [, , $request] = fireApproval([$first, $second], ['hours_to_respond' => 4]);

    expect($request->levels->first()->due_at->toDateTimeString())->toBe('2026-10-15 13:00:00');

    // Still inside the window.
    Carbon::setTestNow('2026-10-15 12:00:00');
    expect(app(EscalateApprovalsAction::class)())->toBe(0);

    Carbon::setTestNow('2026-10-15 13:01:00');
    expect(app(EscalateApprovalsAction::class)())->toBe(1);

    $request = $request->fresh();

    expect($request->status())->toBe(ApprovalStatus::Waiting)
        ->and($request->isAwaiting($second->id))->toBeTrue()
        // Expired, not rejected: nobody said no, nobody said anything.
        ->and($request->levels->first()->status())->toBe(ApprovalStatus::Expired)
        ->and($request->levels->first()->escalated_at)->not->toBeNull()
        // The next person's clock starts now, not when the request was made.
        ->and($request->levels->last()->due_at->toDateTimeString())->toBe('2026-10-15 17:01:00');

    Carbon::setTestNow();
});

test('running out of people rejects rather than approving', function () {
    // An approval that lets itself through when ignored is not an approval.
    Carbon::setTestNow('2026-10-15 09:00:00');

    $only = User::factory()->create();
    [$run, $lead, $request] = fireApproval([$only], ['hours_to_respond' => 1]);

    Carbon::setTestNow('2026-10-15 10:01:00');
    app(EscalateApprovalsAction::class)();

    expect($request->fresh()->status())->toBe(ApprovalStatus::Expired)
        ->and($run->fresh()->status())->toBe(WorkflowRunStatus::Rejected)
        ->and($run->fresh()->message)->toContain('in time')
        ->and($lead->fresh()->company_name)->not->toBe('Approved');

    Carbon::setTestNow();
});

test('a step can be set to approve itself when ignored', function () {
    // A courtesy sign-off that should not stall the work — the opposite of the
    // default, and why the choice is configured rather than assumed.
    Carbon::setTestNow('2026-10-15 09:00:00');

    $approver = User::factory()->create();
    [$run, $lead, $request] = fireApproval([$approver], [
        'hours_to_respond' => 1,
        'on_timeout' => EscalationOutcome::Approve->value,
    ]);

    Carbon::setTestNow('2026-10-15 10:01:00');
    app(EscalateApprovalsAction::class)();

    expect($request->fresh()->status())->toBe(ApprovalStatus::Approved)
        ->and($run->fresh()->status())->toBe(WorkflowRunStatus::Success)
        ->and($lead->fresh()->company_name)->toBe('Approved');

    Carbon::setTestNow();
});

test('a step can be set to reject outright when ignored', function () {
    Carbon::setTestNow('2026-10-15 09:00:00');

    $first = User::factory()->create();
    $second = User::factory()->create();

    [$run, , $request] = fireApproval([$first, $second], [
        'hours_to_respond' => 1,
        'on_timeout' => EscalationOutcome::Reject->value,
    ]);

    Carbon::setTestNow('2026-10-15 10:01:00');
    app(EscalateApprovalsAction::class)();

    // Not passed on, even though somebody else was named.
    expect($request->fresh()->status())->toBe(ApprovalStatus::Expired)
        ->and($run->fresh()->status())->toBe(WorkflowRunStatus::Rejected);

    Carbon::setTestNow();
});

test('an approval with no deadline waits indefinitely', function () {
    // A real choice for something nobody should be able to let lapse by
    // ignoring it.
    Carbon::setTestNow('2026-10-15 09:00:00');

    $approver = User::factory()->create();
    [, , $request] = fireApproval([$approver]);

    expect($request->levels->first()->due_at)->toBeNull();

    Carbon::setTestNow('2027-10-15 09:00:00');

    expect(app(EscalateApprovalsAction::class)())->toBe(0)
        ->and($request->fresh()->status())->toBe(ApprovalStatus::Waiting);

    Carbon::setTestNow();
});

test('the sweep leaves a settled request alone', function () {
    Carbon::setTestNow('2026-10-15 09:00:00');

    $approver = User::factory()->create();
    [, , $request] = fireApproval([$approver], ['hours_to_respond' => 1]);

    app(DecideApprovalAction::class)($request, $approver, true);

    Carbon::setTestNow('2026-10-15 10:01:00');

    expect(app(EscalateApprovalsAction::class)())->toBe(0)
        ->and($request->fresh()->status())->toBe(ApprovalStatus::Approved);

    Carbon::setTestNow();
});

// -- What gets recorded ------------------------------------------------------------------

test('an approval keeps what was being agreed to, in its own words', function () {
    // The steps it describes can be edited afterwards; somebody who approved
    // "set the company name" must not later read as having approved something
    // else.
    $approver = User::factory()->create();
    [, , $request] = fireApproval([$approver]);

    $original = $request->summary;

    expect($original)->not->toBeEmpty();

    $request->workflow->actions()->where('position', 1)->update([
        'config' => json_encode(['field' => 'city', 'value' => 'Somewhere else']),
    ]);

    expect($request->fresh()->summary)->toBe($original);
});

test('an approval outlives the workflow that asked for it', function () {
    $approver = User::factory()->create();
    [, , $request] = fireApproval([$approver]);

    app(DeleteWorkflowAction::class)($request->workflow);

    $request = $request->fresh();

    expect($request)->not->toBeNull()
        ->and($request->workflow_id)->toBeNull()
        ->and($request->workflow_name)->not->toBeEmpty()
        ->and($request->summary)->not->toBeEmpty();
});

test('a step naming nobody fails rather than letting the rest through', function () {
    // Failing open here would mean an approval step that silently approves.
    $workflow = Workflow::factory()->create(['module' => 'leads', 'is_active' => true]);

    WorkflowAction::factory()->for($workflow)->create([
        'type' => WorkflowActionType::RequestApproval->value,
        'position' => 0,
        'config' => ['approvers' => ['user:999999']],
    ]);
    WorkflowAction::factory()->for($workflow)->create([
        'type' => WorkflowActionType::UpdateField->value,
        'position' => 1,
        'config' => ['field' => 'company_name', 'value' => 'Approved'],
    ]);

    app(WorkflowCache::class)->flush();
    $lead = Lead::factory()->create();

    expect(WorkflowRun::query()->sole()->status())->toBe(WorkflowRunStatus::Failed)
        ->and($lead->fresh()->company_name)->not->toBe('Approved');
});

// -- The screen ---------------------------------------------------------------------------

test('the queue shows what is waiting on me and nobody else', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    ApprovalRequest::factory()->create(['summary' => 'Mine to answer'])
        ->levels()->create(['position' => 0, 'approver_id' => $mine->id, 'status' => ApprovalStatus::Waiting->value]);

    ApprovalRequest::factory()->create(['summary' => 'Theirs to answer'])
        ->levels()->create(['position' => 0, 'approver_id' => $theirs->id, 'status' => ApprovalStatus::Waiting->value]);

    Livewire::actingAs($mine)
        ->test(ApprovalsIndex::class)
        ->assertSee('Mine to answer')
        ->assertDontSee('Theirs to answer');
});

test('a later approver does not see it in their queue yet', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    fireApproval([$first, $second]);

    Livewire::actingAs($second)
        ->test(ApprovalsIndex::class)
        ->assertSee('Nothing waiting on you');

    Livewire::actingAs($first)
        ->test(ApprovalsIndex::class)
        ->assertDontSee('Nothing waiting on you');
});

test('approving from the screen carries the workflow on', function () {
    $approver = User::factory()->create();
    [$run, $lead, $request] = fireApproval([$approver]);

    Livewire::actingAs($approver)
        ->test(ApprovalsIndex::class)
        ->set('comments.'.$request->id, 'Agreed.')
        ->call('approve', $request->id);

    expect($request->fresh()->status())->toBe(ApprovalStatus::Approved)
        ->and($request->fresh()->levels->first()->comment)->toBe('Agreed.')
        ->and($lead->fresh()->company_name)->toBe('Approved')
        ->and($run->fresh()->status())->toBe(WorkflowRunStatus::Success);
});

test('rejecting from the screen stops it', function () {
    $approver = User::factory()->create();
    [$run, $lead, $request] = fireApproval([$approver]);

    Livewire::actingAs($approver)
        ->test(ApprovalsIndex::class)
        ->call('reject', $request->id);

    expect($request->fresh()->status())->toBe(ApprovalStatus::Rejected)
        ->and($lead->fresh()->company_name)->not->toBe('Approved')
        ->and($run->fresh()->status())->toBe(WorkflowRunStatus::Rejected);
});

test('somebody who was not asked cannot answer through the screen', function () {
    $approver = User::factory()->create();
    $stranger = User::factory()->create();

    [, , $request] = fireApproval([$approver]);

    Livewire::actingAs($stranger)
        ->test(ApprovalsIndex::class)
        ->call('approve', $request->id)
        ->assertForbidden();

    expect($request->fresh()->status())->toBe(ApprovalStatus::Waiting);
});

test('only somebody who may audit can widen the history to everyone', function () {
    $approver = User::factory()->create();

    ApprovalRequest::factory()->withStatus(ApprovalStatus::Approved)->create(['summary' => 'Somebody else\'s decision']);

    Livewire::actingAs($approver)
        ->test(ApprovalsIndex::class)
        ->set('showAll', true)
        // The toggle is not offered and the query is not widened for somebody
        // without the permission.
        ->assertDontSee('Somebody else\'s decision');

    $auditor = User::factory()->create();

    foreach (PermissionResolver::models(['workflows.approvals']) as $permission) {
        $auditor->givePermissionTo($permission);
    }

    Livewire::actingAs($auditor->fresh())
        ->test(ApprovalsIndex::class)
        ->set('showAll', true)
        ->assertSee('Somebody else\'s decision');
});

test('an overdue level is found by the index the sweep uses', function () {
    Carbon::setTestNow('2026-10-15 09:00:00');

    $level = ApprovalLevel::factory()->dueAt(Carbon::parse('2026-10-15 08:00:00'))->create();
    ApprovalLevel::factory()->dueAt(Carbon::parse('2026-10-15 10:00:00'))->create();
    ApprovalLevel::factory()->create();

    expect(ApprovalLevel::query()->overdue()->pluck('id')->all())->toBe([$level->id])
        ->and($level->isOverdue())->toBeTrue();

    Carbon::setTestNow();
});
