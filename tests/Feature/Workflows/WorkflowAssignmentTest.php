<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadAssignee;
use App\Domain\Workflows\Assignment\AssignmentPointer;
use App\Domain\Workflows\Assignment\AssignmentStrategy;
use App\Domain\Workflows\Enums\WorkflowActionType;
use App\Domain\Workflows\Enums\WorkflowRunStatus;
use App\Domain\Workflows\Enums\WorkflowTrigger;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowAction;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\WorkflowCache;
use App\Models\User;

/**
 * A live workflow on one module that assigns however the config says.
 *
 * @param  array<string, mixed>  $config
 */
function assigningWorkflow(array $config, string $module = 'leads'): Workflow
{
    $workflow = Workflow::factory()->create([
        'module' => $module,
        'trigger_event' => WorkflowTrigger::RecordCreated->value,
        'is_active' => true,
    ]);

    WorkflowAction::factory()->for($workflow)->create([
        'type' => WorkflowActionType::AssignOwner->value,
        'config' => $config,
    ]);

    app(WorkflowCache::class)->flush();

    return $workflow;
}

beforeEach(function () {
    app(WorkflowCache::class)->flush();
});

// -- Round robin: distribution over N records --------------------------------------

test('round robin shares nine records evenly between three people', function () {
    $pool = User::factory()->count(3)->create();

    assigningWorkflow([
        'assign_to_strategy' => AssignmentStrategy::RoundRobin->value,
        'pool' => $pool->map(fn (User $user): string => 'user:'.$user->id)->all(),
    ]);

    for ($i = 0; $i < 9; $i++) {
        Lead::factory()->create();
    }

    $counts = LeadAssignee::query()
        ->whereIn('user_id', $pool->pluck('id'))
        ->selectRaw('user_id, count(*) as total')
        ->groupBy('user_id')
        ->pluck('total', 'user_id');

    expect($counts)->toHaveCount(3);

    foreach ($pool as $user) {
        expect((int) $counts[$user->id])->toBe(3);
    }
});

test('round robin goes round in the order the pool is written', function () {
    // The order is the fairness. Taking whatever order the database returned
    // would make the sequence arbitrary between deployments.
    $first = User::factory()->create();
    $second = User::factory()->create();
    $third = User::factory()->create();

    assigningWorkflow([
        'assign_to_strategy' => AssignmentStrategy::RoundRobin->value,
        'pool' => ['user:'.$third->id, 'user:'.$first->id, 'user:'.$second->id],
    ]);

    $owners = [];

    for ($i = 0; $i < 4; $i++) {
        $owners[] = leadOwnerId(Lead::factory()->create()->fresh());
    }

    expect($owners)->toBe([$third->id, $first->id, $second->id, $third->id]);
});

test('a remainder is spread rather than piled on the first person', function () {
    // Ten records across three people is 4/3/3, not 4/4/2.
    $pool = User::factory()->count(3)->create();

    assigningWorkflow([
        'assign_to_strategy' => AssignmentStrategy::RoundRobin->value,
        'pool' => $pool->map(fn (User $user): string => 'user:'.$user->id)->all(),
    ]);

    for ($i = 0; $i < 10; $i++) {
        Lead::factory()->create();
    }

    $counts = LeadAssignee::query()
        ->whereIn('user_id', $pool->pluck('id'))
        ->selectRaw('user_id, count(*) as total')
        ->groupBy('user_id')
        ->pluck('total')
        ->map(fn (mixed $count): int => (int) $count)
        ->sort()
        ->values()
        ->all();

    expect($counts)->toBe([3, 3, 4]);
});

test('two workflows taking turns through the same team do not share a turn', function () {
    // A shared pointer would hand two records to one person and none to
    // another, which is the whole thing round robin exists to avoid.
    $pool = User::factory()->count(2)->create();
    $poolConfig = $pool->map(fn (User $user): string => 'user:'.$user->id)->all();

    assigningWorkflow([
        'assign_to_strategy' => AssignmentStrategy::RoundRobin->value,
        'pool' => $poolConfig,
    ]);
    assigningWorkflow([
        'assign_to_strategy' => AssignmentStrategy::RoundRobin->value,
        'pool' => $poolConfig,
    ], 'contacts');

    $lead = Lead::factory()->create();
    $contact = Contact::factory()->create();

    // Each workflow starts its own turn at the top of the pool.
    expect(leadOwnerId($lead->fresh()))->toBe($pool[0]->id)
        ->and($contact->fresh()->owner_id)->toBe($pool[0]->id)
        ->and(AssignmentPointer::query()->count())->toBe(2);
});

test('a pool member who no longer exists is skipped, not given records', function () {
    $present = User::factory()->create();

    assigningWorkflow([
        'assign_to_strategy' => AssignmentStrategy::RoundRobin->value,
        'pool' => ['user:999999', 'user:'.$present->id],
    ]);

    $lead = Lead::factory()->create();

    expect(leadOwnerId($lead->fresh()))->toBe($present->id);
});

test('a round robin with an empty pool assigns nobody and is not a failure', function () {
    assigningWorkflow([
        'assign_to_strategy' => AssignmentStrategy::RoundRobin->value,
        'pool' => [],
    ]);

    $owner = User::factory()->create();
    $lead = Lead::factory()->ownedBy($owner)->create();

    expect(leadOwnerId($lead->fresh()))->toBe($owner->id)
        ->and(WorkflowRun::query()->sole()->status())
        ->toBe(WorkflowRunStatus::Success);
});

// -- Load-based ----------------------------------------------------------------------

test('load-based gives the record to whoever is carrying the least', function () {
    $busy = User::factory()->create();
    $quiet = User::factory()->create();

    Lead::factory()->count(5)->ownedBy($busy)->create();
    Lead::factory()->count(1)->ownedBy($quiet)->create();

    assigningWorkflow([
        'assign_to_strategy' => AssignmentStrategy::LoadBased->value,
        'pool' => ['user:'.$busy->id, 'user:'.$quiet->id],
    ]);

    expect(leadOwnerId(Lead::factory()->create()->fresh()))->toBe($quiet->id);
});

test('load-based levels an uneven team out over several records', function () {
    $busy = User::factory()->create();
    $quiet = User::factory()->create();

    Lead::factory()->count(4)->ownedBy($busy)->create();

    assigningWorkflow([
        'assign_to_strategy' => AssignmentStrategy::LoadBased->value,
        'pool' => ['user:'.$busy->id, 'user:'.$quiet->id],
    ]);

    // Four more: the first four go to the quiet one until they are level, then
    // they alternate.
    for ($i = 0; $i < 4; $i++) {
        Lead::factory()->create();
    }

    $counts = LeadAssignee::query()
        ->selectRaw('user_id, count(*) as total')
        ->groupBy('user_id')
        ->pluck('total', 'user_id');

    expect((int) $counts[$busy->id])->toBe(4)
        ->and((int) $counts[$quiet->id])->toBe(4);
});

test('work that is finished is not a load', function () {
    // Counting closed deals would leave the best salesperson permanently at the
    // back of the queue.
    $closer = User::factory()->create();
    $other = User::factory()->create();

    Deal::factory()->count(6)->create(['owner_id' => $closer->id, 'stage' => DealStage::Won->value]);
    Deal::factory()->count(2)->create(['owner_id' => $other->id, 'stage' => DealStage::Proposal->value]);

    assigningWorkflow([
        'assign_to_strategy' => AssignmentStrategy::LoadBased->value,
        'pool' => ['user:'.$closer->id, 'user:'.$other->id],
    ], 'deals');

    expect(Deal::factory()->create()->fresh()->owner_id)->toBe($closer->id);
});

test('a removed record is not a load either', function () {
    // The soft-delete trap: dropping to the base query builder to count would
    // discard the global scope and include them.
    $busy = User::factory()->create();
    $quiet = User::factory()->create();

    Lead::factory()->count(4)->ownedBy($quiet)->create()->each->delete();
    Lead::factory()->count(1)->ownedBy($busy)->create();

    assigningWorkflow([
        'assign_to_strategy' => AssignmentStrategy::LoadBased->value,
        'pool' => ['user:'.$busy->id, 'user:'.$quiet->id],
    ]);

    // The quiet one has four deleted leads and no live ones, so they are the
    // lighter of the two.
    expect(leadOwnerId(Lead::factory()->create()->fresh()))->toBe($quiet->id);
});

test('a tie breaks the same way every time', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    assigningWorkflow([
        'assign_to_strategy' => AssignmentStrategy::LoadBased->value,
        'pool' => ['user:'.$first->id, 'user:'.$second->id],
    ]);

    // Both start with nothing; the pool's own order settles it.
    expect(leadOwnerId(Lead::factory()->create()->fresh()))->toBe($first->id);
});

// -- Territory -------------------------------------------------------------------------

test('territory routes each record by what its field says', function () {
    $bd = User::factory()->create();
    $uk = User::factory()->create();
    $rest = User::factory()->create();

    assigningWorkflow([
        'assign_to_strategy' => AssignmentStrategy::Territory->value,
        'territory_field' => 'country',
        'territories' => [
            ['value' => 'Bangladesh', 'user' => 'user:'.$bd->id],
            ['value' => 'United Kingdom', 'user' => 'user:'.$uk->id],
        ],
        'territory_fallback' => 'user:'.$rest->id,
    ]);

    $dhaka = Lead::factory()->create(['country' => 'Bangladesh']);
    $london = Lead::factory()->create(['country' => 'United Kingdom']);
    $elsewhere = Lead::factory()->create(['country' => 'Peru']);

    expect(leadOwnerId($dhaka->fresh()))->toBe($bd->id)
        ->and(leadOwnerId($london->fresh()))->toBe($uk->id)
        // Nothing matched, so the fallback caught it rather than the record
        // going nowhere.
        ->and(leadOwnerId($elsewhere->fresh()))->toBe($rest->id);
});

test('a territory matches however the value was typed', function () {
    // The list is typed by one person and the record by another.
    $bd = User::factory()->create();

    assigningWorkflow([
        'assign_to_strategy' => AssignmentStrategy::Territory->value,
        'territory_field' => 'country',
        'territories' => [['value' => 'Bangladesh', 'user' => 'user:'.$bd->id]],
    ]);

    expect(leadOwnerId(Lead::factory()->create(['country' => '  bangladesh '])->fresh()))->toBe($bd->id);
});

test('a territory step with no fallback leaves an unmatched record alone', function () {
    $bd = User::factory()->create();
    $owner = User::factory()->create();

    assigningWorkflow([
        'assign_to_strategy' => AssignmentStrategy::Territory->value,
        'territory_field' => 'country',
        'territories' => [['value' => 'Bangladesh', 'user' => 'user:'.$bd->id]],
    ]);

    $lead = Lead::factory()->ownedBy($owner)->create(['country' => 'Peru']);

    expect(leadOwnerId($lead->fresh()))->toBe($owner->id);
});

test('a territory cannot match on a field the module does not have', function () {
    $anybody = User::factory()->create();
    $owner = User::factory()->create();

    assigningWorkflow([
        'assign_to_strategy' => AssignmentStrategy::Territory->value,
        'territory_field' => 'password',
        'territories' => [['value' => 'anything', 'user' => 'user:'.$anybody->id]],
    ]);

    $lead = Lead::factory()->ownedBy($owner)->create();

    expect(leadOwnerId($lead->fresh()))->toBe($owner->id);
});

// -- The plain cases still work -----------------------------------------------------------

test('a step written before the strategies still names one person', function () {
    // Read rather than migrated: `user:5` is unambiguous, and rewriting stored
    // configs to add a key that can be inferred is a migration that can go
    // wrong for no gain.
    $target = User::factory()->create();

    assigningWorkflow(['assign_to' => 'user:'.$target->id]);

    expect(leadOwnerId(Lead::factory()->create()->fresh()))->toBe($target->id);
});

test('every strategy has a label and a description', function (string $value) {
    $strategy = AssignmentStrategy::from($value);

    expect($strategy->label())->not->toBeEmpty()
        ->and($strategy->description())->not->toBeEmpty();
})->with(array_column(AssignmentStrategy::cases(), 'value'));

test('only the distributing strategies need a pool', function () {
    expect(AssignmentStrategy::RoundRobin->needsPool())->toBeTrue()
        ->and(AssignmentStrategy::LoadBased->needsPool())->toBeTrue()
        ->and(AssignmentStrategy::Fixed->needsPool())->toBeFalse()
        ->and(AssignmentStrategy::Territory->needsPool())->toBeFalse();
});

// -- The pointer ----------------------------------------------------------------------------

test('the pointer wraps round the pool rather than running off the end', function () {
    expect(AssignmentPointer::take('probe', 3))->toBe(0)
        ->and(AssignmentPointer::take('probe', 3))->toBe(1)
        ->and(AssignmentPointer::take('probe', 3))->toBe(2)
        ->and(AssignmentPointer::take('probe', 3))->toBe(0);
});

test('a pool that shrinks does not point past its end', function () {
    // Somebody leaves the team; the stored position is now larger than the pool.
    AssignmentPointer::query()->create(['key' => 'probe', 'position' => 97]);

    expect(AssignmentPointer::take('probe', 2))->toBeLessThan(2);
});

test('the pointer refuses an empty pool rather than dividing by zero', function () {
    expect(AssignmentPointer::take('probe', 0))->toBe(0)
        ->and(AssignmentPointer::query()->count())->toBe(0);
});
