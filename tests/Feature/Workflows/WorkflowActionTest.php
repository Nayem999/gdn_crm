<?php

use App\Domain\Activities\Models\Activity;
use App\Domain\CustomFields\Enums\CustomFieldType;
use App\Domain\CustomFields\Models\CustomField;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Workflows\Actions\RunWorkflowAction;
use App\Domain\Workflows\Enums\WorkflowActionType;
use App\Domain\Workflows\Enums\WorkflowRunStatus;
use App\Domain\Workflows\Enums\WorkflowTrigger;
use App\Domain\Workflows\Handlers\WorkflowActionHandler;
use App\Domain\Workflows\Handlers\WorkflowActionRegistry;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowAction;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Models\WorkflowRunStep;
use App\Domain\Workflows\Webhooks\WebhookTarget;
use App\Domain\Workflows\WorkflowCache;
use App\Mail\NotificationMail;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * A live workflow on leads with one step of the given type, and the lead it
 * fires for. Returns the run it produced.
 *
 * @param  array<string, mixed>  $config
 * @param  array<string, mixed>  $leadAttributes
 * @return array{0: WorkflowRun, 1: Lead, 2: Workflow}
 */
function fireStep(WorkflowActionType $type, array $config, array $leadAttributes = [], array $stepOverrides = []): array
{
    $workflow = Workflow::factory()->create([
        'module' => 'leads',
        'trigger_event' => WorkflowTrigger::RecordCreated->value,
        'is_active' => true,
    ]);

    WorkflowAction::factory()->for($workflow)->create([
        'type' => $type->value,
        'config' => $config,
        ...$stepOverrides,
    ]);

    app(WorkflowCache::class)->flush();

    $lead = Lead::factory()->create($leadAttributes);

    return [WorkflowRun::query()->sole()->fresh(), $lead->fresh(), $workflow];
}

beforeEach(function () {
    app(WorkflowCache::class)->flush();
    // Nothing in these tests should actually reach a mail server or the
    // network; anything that tries is a bug in the handler, not the test.
    Mail::fake();
    Http::preventStrayRequests();
});

// -- Each action executes and logs ----------------------------------------------

test('update field sets the field and says what it set', function () {
    [$run, $lead] = fireStep(WorkflowActionType::UpdateField, [
        'field' => 'company_name',
        'value' => 'Golden Infotech',
    ]);

    expect($lead->company_name)->toBe('Golden Infotech')
        ->and($run->status())->toBe(WorkflowRunStatus::Success);

    $step = WorkflowRunStep::query()->sole();

    expect($step->status())->toBe(WorkflowRunStatus::Success)
        ->and($step->message)->toContain('Golden Infotech')
        ->and($step->result['field'])->toBe('company_name');
});

test('update field can answer a custom field', function () {
    CustomField::factory()->create([
        'module' => 'leads',
        'key' => 'budget_band',
        'label' => 'Budget band',
        'type' => CustomFieldType::Text->value,
        'is_active' => true,
    ]);

    [$run, $lead] = fireStep(WorkflowActionType::UpdateField, [
        'field' => 'cf_budget_band',
        'value' => 'Enterprise',
    ]);

    expect($lead->customField('budget_band'))->toBe('Enterprise')
        ->and($run->status())->toBe(WorkflowRunStatus::Success);
});

test('a status move goes through the action that owns it', function () {
    // ChangeLeadStatusAction calls itself the only thing that moves a lead's
    // status, and the transition rules live there. A workflow writing the
    // column would be the one route that skips them.
    [$run, $lead] = fireStep(WorkflowActionType::UpdateField, [
        'field' => 'status',
        'value' => LeadStatus::Contacted->value,
    ]);

    expect($lead->status())->toBe(LeadStatus::Contacted)
        ->and($run->status())->toBe(WorkflowRunStatus::Success)
        // The stamp the status action keeps, which a forceFill would not have
        // written.
        ->and($lead->status_changed_at)->not->toBeNull();
});

test('a refused status move fails with the reason as its message', function () {
    [$run] = fireStep(
        WorkflowActionType::UpdateField,
        ['field' => 'status', 'value' => LeadStatus::Converted->value],
    );

    $step = WorkflowRunStep::query()->sole();

    expect($run->status())->toBe(WorkflowRunStatus::Failed)
        ->and($step->status())->toBe(WorkflowRunStatus::Failed)
        // The message is the transition rule's own words, which is what
        // somebody reading the log needs.
        ->and($step->message)->toContain('cannot move to');
});

test('assign owner hands the record over and logs to whom', function () {
    $target = User::factory()->create(['name' => 'Priya Ramanathan']);

    [$run, $lead] = fireStep(WorkflowActionType::AssignOwner, ['assign_to' => 'user:'.$target->id]);

    expect($lead->owner_id)->toBe($target->id)
        ->and($run->status())->toBe(WorkflowRunStatus::Success)
        ->and(WorkflowRunStep::query()->sole()->message)->toContain('Priya Ramanathan');
});

test('an assignment that matches nobody is skipped, not failed', function () {
    // A red row every night for a rule that is simply not applicable would
    // bury the failures that matter.
    [$run] = fireStep(WorkflowActionType::AssignOwner, ['assign_to' => 'user:999999']);

    expect($run->status())->toBe(WorkflowRunStatus::Success)
        ->and(WorkflowRunStep::query()->sole()->status())->toBe(WorkflowRunStatus::Skipped);
});

test('create record makes a task linked back to what caused it', function () {
    $owner = User::factory()->create();

    [$run, $lead] = fireStep(
        WorkflowActionType::CreateRecord,
        ['module' => 'activities', 'subject' => 'Call the new lead', 'due_in_days' => 3],
        ['owner_id' => $owner->id],
    );

    $activity = Activity::query()->sole();

    expect($activity->subject)->toBe('Call the new lead')
        ->and($activity->owner_id)->toBe($owner->id)
        // A follow-up task floating free of the lead it is about is worse than
        // no task at all.
        ->and($activity->related_id)->toBe($lead->id)
        ->and($activity->related_type)->toBe($lead->getMorphClass())
        ->and($activity->due_at->isAfter(now()->addDays(2)))->toBeTrue()
        ->and($run->status())->toBe(WorkflowRunStatus::Success);

    expect(WorkflowRunStep::query()->sole()->result['id'])->toBe($activity->id);
});

test('create record refuses a module the registry does not list', function () {
    [$run] = fireStep(WorkflowActionType::CreateRecord, ['module' => 'users', 'subject' => 'x']);

    expect($run->status())->toBe(WorkflowRunStatus::Failed)
        ->and(WorkflowRunStep::query()->sole()->message)->toContain('not a module');
});

test('send email queues a merged message to the record', function () {
    [$run] = fireStep(
        WorkflowActionType::SendEmail,
        [
            'recipient' => 'record_email',
            'subject' => 'Hello {{record.first_name}}',
            'template' => 'Thanks for getting in touch, {{record.first_name}}.',
        ],
        ['first_name' => 'Priya', 'email' => 'priya@example.com'],
    );

    expect($run->status())->toBe(WorkflowRunStatus::Success);

    Mail::assertQueued(NotificationMail::class, function (NotificationMail $mail): bool {
        return $mail->hasTo('priya@example.com')
            && $mail->subject === 'Hello Priya'
            && str_contains($mail->body, 'Thanks for getting in touch, Priya.');
    });
});

test('a merge field is substituted, never executed', function () {
    // The template is admin-authored text. If it were compiled, an admin — or
    // anything that reached a merged value — could run code.
    [$run] = fireStep(
        WorkflowActionType::SendEmail,
        [
            'recipient' => 'address:ops@example.com',
            'subject' => 'Test',
            'template' => 'Value: {{ record.company_name }}',
        ],
        ['company_name' => '{{ app.name }}'],
    );

    expect($run->status())->toBe(WorkflowRunStatus::Success);

    Mail::assertQueued(NotificationMail::class, function (NotificationMail $mail): bool {
        // The record's own value is inserted literally; it is not merged again.
        return str_contains($mail->body, 'Value: {{ app.name }}');
    });
});

test('an email with nowhere to go is skipped, not failed', function () {
    // Plenty of leads arrive without an address. That is not a fault.
    [$run] = fireStep(
        WorkflowActionType::SendEmail,
        ['recipient' => 'record_email', 'subject' => 'Hi', 'template' => 'Hello'],
        ['email' => null],
    );

    expect($run->status())->toBe(WorkflowRunStatus::Success)
        ->and(WorkflowRunStep::query()->sole()->status())->toBe(WorkflowRunStatus::Skipped);

    Mail::assertNothingQueued();
});

test('send notification goes through the engine, not round it', function () {
    $owner = User::factory()->create();

    [$run] = fireStep(
        WorkflowActionType::SendNotification,
        ['recipient' => 'record_owner', 'message' => 'A lead worth calling has arrived.'],
        ['owner_id' => $owner->id],
    );

    expect($run->status())->toBe(WorkflowRunStatus::Success);

    // Through the 1.9 engine, so the admin matrix, personal preferences and
    // quiet hours all apply exactly as they do to everything else.
    $log = NotificationLog::query()->where('event', 'workflow.notified')->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($owner->id);
});

test('call webhook posts the record and records the response', function () {
    Http::fake(['https://example.com/hook' => Http::response(['ok' => true], 200)]);

    [$run, $lead] = fireStep(
        WorkflowActionType::CallWebhook,
        ['url' => 'https://example.com/hook'],
        ['first_name' => 'Priya'],
    );

    expect($run->status())->toBe(WorkflowRunStatus::Success)
        ->and(WorkflowRunStep::query()->sole()->result['status'])->toBe(200);

    Http::assertSent(function ($request) use ($lead): bool {
        return $request->url() === 'https://example.com/hook'
            && $request['record']['first_name'] === 'Priya'
            && $request['record']['id'] === $lead->id
            && $request['module'] === 'leads';
    });
});

test('a webhook sends only the fields the module declares', function () {
    // Not toArray(): a column somebody adds to a table must not silently start
    // leaving the building.
    Http::fake(['https://example.com/hook' => Http::response([], 200)]);

    fireStep(WorkflowActionType::CallWebhook, ['url' => 'https://example.com/hook']);

    Http::assertSent(function ($request): bool {
        $record = $request['record'];

        return array_key_exists('first_name', $record)
            && ! array_key_exists('deleted_at', $record)
            && ! array_key_exists('updated_at', $record);
    });
});

test('a webhook that answers badly is a failed step', function () {
    Http::fake(['https://example.com/hook' => Http::response('nope', 500)]);

    [$run] = fireStep(WorkflowActionType::CallWebhook, ['url' => 'https://example.com/hook']);

    expect($run->status())->toBe(WorkflowRunStatus::Failed)
        ->and(WorkflowRunStep::query()->sole()->message)->toContain('500');
});

// -- Where a webhook may not go --------------------------------------------------

test('a webhook cannot be pointed inside this network', function (string $url) {
    // A configurable outbound request is an SSRF primitive: without this,
    // anybody who can write a workflow can read the cloud metadata service or
    // reach something listening only on the private network.
    expect(WebhookTarget::allows($url))->toBeFalse();
})->with([
    'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
    'loopback' => ['http://127.0.0.1:3306/'],
    'localhost by name' => ['http://localhost/admin'],
    'private 10' => ['http://10.0.0.5/'],
    'private 192.168' => ['http://192.168.1.1/'],
    'private 172.16' => ['http://172.16.0.1/'],
    'ipv6 loopback' => ['http://[::1]/'],
    'file scheme' => ['file:///etc/passwd'],
    'gopher scheme' => ['gopher://127.0.0.1:11211/'],
    'not a url' => ['just some text'],
]);

test('a webhook step refuses an internal address before sending anything', function () {
    [$run] = fireStep(WorkflowActionType::CallWebhook, ['url' => 'http://169.254.169.254/latest/meta-data/']);

    expect($run->status())->toBe(WorkflowRunStatus::Failed)
        ->and(WorkflowRunStep::query()->sole()->message)->toContain('inside this network');

    // preventStrayRequests would have thrown if anything had actually gone out.
    Http::assertNothingSent();
});

test('a public address is allowed', function () {
    expect(WebhookTarget::allows('https://example.com/hook'))->toBeTrue();
});

// -- What a step may write --------------------------------------------------------

test('a step cannot set a field the module does not offer', function () {
    [$run, $lead] = fireStep(WorkflowActionType::UpdateField, ['field' => 'id', 'value' => 999]);

    expect($run->status())->toBe(WorkflowRunStatus::Failed)
        ->and($lead->id)->not->toBe(999)
        ->and(WorkflowRunStep::query()->sole()->message)->toContain('no field "id"');
});

test('a step cannot set a field the model does not accept', function () {
    // created_at is in the module's filter set but not its fillable: a workflow
    // can filter on when a lead was captured and cannot rewrite it.
    [$run] = fireStep(WorkflowActionType::UpdateField, ['field' => 'created_at', 'value' => '2020-01-01']);

    expect($run->status())->toBe(WorkflowRunStatus::Failed)
        ->and(WorkflowRunStep::query()->sole()->message)->toContain('no field "created_at"');
});

// -- How a run behaves --------------------------------------------------------------

test('steps run in order and each writes a row', function () {
    $target = User::factory()->create();
    $workflow = Workflow::factory()->create(['module' => 'leads', 'is_active' => true]);

    WorkflowAction::factory()->for($workflow)->create([
        'type' => WorkflowActionType::UpdateField->value,
        'config' => ['field' => 'company_name', 'value' => 'First'],
        'position' => 0,
    ]);
    WorkflowAction::factory()->for($workflow)->create([
        'type' => WorkflowActionType::AssignOwner->value,
        'config' => ['assign_to' => 'user:'.$target->id],
        'position' => 1,
    ]);

    app(WorkflowCache::class)->flush();

    $lead = Lead::factory()->create();

    $steps = WorkflowRunStep::query()->orderBy('position')->get();

    expect($steps)->toHaveCount(2)
        ->and($steps[0]->actionType())->toBe(WorkflowActionType::UpdateField)
        ->and($steps[1]->actionType())->toBe(WorkflowActionType::AssignOwner)
        ->and($lead->fresh()->company_name)->toBe('First')
        ->and($lead->fresh()->owner_id)->toBe($target->id);
});

test('a failing step stops the rest when it says it should', function () {
    $workflow = Workflow::factory()->create(['module' => 'leads', 'is_active' => true]);

    WorkflowAction::factory()->for($workflow)->create([
        'type' => WorkflowActionType::UpdateField->value,
        'config' => ['field' => 'not_a_field', 'value' => 'x'],
        'position' => 0,
        'stop_on_failure' => true,
    ]);
    WorkflowAction::factory()->for($workflow)->create([
        'type' => WorkflowActionType::UpdateField->value,
        'config' => ['field' => 'company_name', 'value' => 'Never set'],
        'position' => 1,
    ]);

    app(WorkflowCache::class)->flush();

    $lead = Lead::factory()->create();

    expect(WorkflowRun::query()->sole()->status())->toBe(WorkflowRunStatus::Failed)
        ->and(WorkflowRunStep::query()->count())->toBe(1)
        ->and($lead->fresh()->company_name)->not->toBe('Never set');
});

test('a failing step the workflow can live without does not stop the rest', function () {
    // Sending a courtesy email should not stop the follow-up task being made.
    $workflow = Workflow::factory()->create(['module' => 'leads', 'is_active' => true]);

    WorkflowAction::factory()->for($workflow)->create([
        'type' => WorkflowActionType::UpdateField->value,
        'config' => ['field' => 'not_a_field', 'value' => 'x'],
        'position' => 0,
        'stop_on_failure' => false,
    ]);
    WorkflowAction::factory()->for($workflow)->create([
        'type' => WorkflowActionType::UpdateField->value,
        'config' => ['field' => 'company_name', 'value' => 'Still set'],
        'position' => 1,
    ]);

    app(WorkflowCache::class)->flush();

    $lead = Lead::factory()->create();

    expect(WorkflowRunStep::query()->count())->toBe(2)
        ->and($lead->fresh()->company_name)->toBe('Still set')
        // The run still failed: something in it did not work.
        ->and(WorkflowRun::query()->sole()->status())->toBe(WorkflowRunStatus::Failed);
});

test('a step that is not finished being set up is skipped rather than attempted', function () {
    // Otherwise it fails once per firing, forever, burying the real failures.
    [$run] = fireStep(WorkflowActionType::SendEmail, ['recipient' => 'record_email']);

    expect(WorkflowRunStep::query()->sole()->status())->toBe(WorkflowRunStatus::Skipped)
        ->and($run->status())->toBe(WorkflowRunStatus::Success);
});

test('a workflow acting on a record does not trigger itself', function () {
    // The loop: the action sets a field, the update trigger fires, the workflow
    // runs, the action sets the field.
    $workflow = Workflow::factory()->create([
        'module' => 'leads',
        'trigger_event' => WorkflowTrigger::RecordUpdated->value,
        'is_active' => true,
    ]);
    WorkflowAction::factory()->for($workflow)->create([
        'type' => WorkflowActionType::UpdateField->value,
        'config' => ['field' => 'company_name', 'value' => 'Set by the workflow'],
    ]);

    app(WorkflowCache::class)->flush();

    $lead = Lead::factory()->create();
    $lead->update(['first_name' => 'Priya']);

    expect(WorkflowRun::query()->count())->toBe(1)
        ->and($lead->fresh()->company_name)->toBe('Set by the workflow');
});

test('a run is carried out once, however often the job is delivered', function () {
    [$run] = fireStep(WorkflowActionType::UpdateField, ['field' => 'company_name', 'value' => 'Once']);

    expect(WorkflowRunStep::query()->count())->toBe(1);

    // A queue that delivers twice must not do the work twice; the status is
    // the claim.
    app(RunWorkflowAction::class)($run->fresh());

    expect(WorkflowRunStep::query()->count())->toBe(1);
});

test('a run whose record vanished is skipped rather than failed', function () {
    $workflow = Workflow::factory()->create(['module' => 'leads']);
    $lead = Lead::factory()->create();
    $run = WorkflowRun::factory()->forWorkflow($workflow)->about($lead)->create([
        'status' => WorkflowRunStatus::Pending->value,
    ]);
    WorkflowAction::factory()->for($workflow)->create();

    $lead->forceDelete();

    $run = app(RunWorkflowAction::class)($run);

    expect($run->status())->toBe(WorkflowRunStatus::Skipped)
        ->and($run->message)->toContain('no longer exists');
});

test('a run records how long it took', function () {
    [$run] = fireStep(WorkflowActionType::UpdateField, ['field' => 'company_name', 'value' => 'x']);

    expect($run->finished_at)->not->toBeNull()
        ->and($run->duration_ms)->toBeGreaterThanOrEqual(0);
});

// -- The registry -------------------------------------------------------------------

test('every action type has a handler', function (string $value) {
    $type = WorkflowActionType::from($value);

    expect(WorkflowActionRegistry::for($type))
        ->toBeInstanceOf(WorkflowActionHandler::class);
})->with(array_column(WorkflowActionType::cases(), 'value'));
