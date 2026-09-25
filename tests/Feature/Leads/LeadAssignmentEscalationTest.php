<?php

use App\Domain\Leads\Actions\EscalateLeadAssignmentsAction;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadAssignee;
use App\Domain\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

function sweepEscalations(?Carbon $now = null): int
{
    return app(EscalateLeadAssignmentsAction::class)($now);
}

/**
 * Backdates a tier's rows so the sweep sees them as assigned (and, if given,
 * escalated) at a specific moment — the factory itself always stamps "now".
 */
function backdateTier(Lead $lead, int $priority, Carbon $assignedAt, ?Carbon $escalatedAt = null): void
{
    LeadAssignee::query()
        ->where('lead_id', $lead->id)
        ->where('priority', $priority)
        ->update(['assigned_at' => $assignedAt, 'escalated_at' => $escalatedAt]);
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-01 09:00:00');
    app(SettingsManager::class)->set('leads.escalation_hours', 24);
    app(SettingsManager::class)->flush();
});

// -- The window -----------------------------------------------------------------

test('a tier that has gone quiet past the window escalates to the next one', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $lead = Lead::factory()->assignedTo($first, priority: 1)->assignedTo($second, priority: 2)->create();
    backdateTier($lead, 1, now()->subHours(25));

    expect(sweepEscalations())->toBe(1);

    $rows = $lead->assignees()->get()->keyBy('priority');

    expect($rows[1]->escalated_at)->not->toBeNull()
        ->and($rows[2]->escalated_at)->toBeNull();
});

test('a tier still inside its window is left alone', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $lead = Lead::factory()->assignedTo($first, priority: 1)->assignedTo($second, priority: 2)->create();
    backdateTier($lead, 1, now()->subHours(23));

    expect(sweepEscalations())->toBe(0);

    expect($lead->assignees()->where('priority', 1)->value('escalated_at'))->toBeNull();
});

test('the escalation hour is exact, not a moment either side', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $lead = Lead::factory()->assignedTo($first, priority: 1)->assignedTo($second, priority: 2)->create();
    backdateTier($lead, 1, now()->subHours(24));

    expect(sweepEscalations())->toBe(1);
});

// -- One rung at a time -----------------------------------------------------------

test('escalation moves one rung per sweep, not straight to the end of the ladder', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $third = User::factory()->create();

    $lead = Lead::factory()
        ->assignedTo($first, priority: 1)
        ->assignedTo($second, priority: 2)
        ->assignedTo($third, priority: 3)
        ->create();

    // All three tiers are well past the window, but only the first has ever
    // had a turn — the second and third have not started their clock yet.
    backdateTier($lead, 1, now()->subDays(10));
    backdateTier($lead, 2, now()->subDays(10));
    backdateTier($lead, 3, now()->subDays(10));

    expect(sweepEscalations())->toBe(1);

    $rows = $lead->assignees()->get()->keyBy('priority');

    expect($rows[1]->escalated_at)->not->toBeNull()
        ->and($rows[2]->escalated_at)->toBeNull()
        ->and($rows[3]->escalated_at)->toBeNull();
});

test("the next tier's clock starts when its turn begins, not when it was first assigned", function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $lead = Lead::factory()->assignedTo($first, priority: 1)->assignedTo($second, priority: 2)->create();

    // Both were put on the ladder together, a long time ago, and tier one only
    // just escalated a moment ago.
    backdateTier($lead, 1, now()->subDays(10), escalatedAt: now()->subMinutes(5));
    backdateTier($lead, 2, now()->subDays(10));

    // Without the later-of-the-two rule this would escalate immediately,
    // since the second tier's own assigned_at is ten days stale.
    expect(sweepEscalations())->toBe(0);
});

test('a tier whose own turn ended a full window ago escalates again', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $third = User::factory()->create();

    $lead = Lead::factory()
        ->assignedTo($first, priority: 1)
        ->assignedTo($second, priority: 2)
        ->assignedTo($third, priority: 3)
        ->create();

    backdateTier($lead, 1, now()->subDays(10), escalatedAt: now()->subHours(25));
    backdateTier($lead, 2, now()->subDays(10));

    expect(sweepEscalations())->toBe(1);

    $rows = $lead->assignees()->get()->keyBy('priority');

    expect($rows[2]->escalated_at)->not->toBeNull()
        ->and($rows[3]->escalated_at)->toBeNull();
});

test('the last rung has nobody left to hand the lead to', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $lead = Lead::factory()->assignedTo($first, priority: 1)->assignedTo($second, priority: 2)->create();

    backdateTier($lead, 1, now()->subDays(10), escalatedAt: now()->subDays(9));
    backdateTier($lead, 2, now()->subDays(10));

    expect(sweepEscalations())->toBe(0);

    // The last tier's own escalated_at is never touched — there is nowhere
    // further for it to go.
    expect($lead->assignees()->where('priority', 2)->value('escalated_at'))->toBeNull();
});

// -- What is not on the ladder at all ---------------------------------------------

test('a single prioritised tier has nowhere to escalate to', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    // Two people share the top (and only) rung — a tie, not a ladder.
    $lead = Lead::factory()->assignedTo($first, priority: 1)->assignedTo($second, priority: 1)->create();
    backdateTier($lead, 1, now()->subDays(10));

    expect(sweepEscalations())->toBe(0);
});

test('a lead with no prioritised assignees at all is never swept up', function () {
    Lead::factory()->create();

    expect(sweepEscalations())->toBe(0);
});

test('a co-assignee with no priority is left off the ladder entirely', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $bystander = User::factory()->create();

    $lead = Lead::factory()
        ->assignedTo($first, priority: 1)
        ->assignedTo($second, priority: 2)
        ->assignedTo($bystander)
        ->create();

    backdateTier($lead, 1, now()->subDays(10));

    expect(sweepEscalations())->toBe(1);

    expect($lead->assignees()->where('user_id', $bystander->id)->value('escalated_at'))->toBeNull();
});

// -- Turned off, or not applicable ------------------------------------------------

test('an escalation window of "never" turns the sweep off entirely', function () {
    app(SettingsManager::class)->set('leads.escalation_hours', 0);
    app(SettingsManager::class)->flush();

    $first = User::factory()->create();
    $second = User::factory()->create();

    $lead = Lead::factory()->assignedTo($first, priority: 1)->assignedTo($second, priority: 2)->create();
    backdateTier($lead, 1, now()->subDays(10));

    expect(sweepEscalations())->toBe(0);
});

test('a converted lead is not escalated', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $lead = Lead::factory()
        ->assignedTo($first, priority: 1)
        ->assignedTo($second, priority: 2)
        ->create(['status' => LeadStatus::Converted->value]);

    backdateTier($lead, 1, now()->subDays(10));

    expect(sweepEscalations())->toBe(0);
});

// -- Once, and only once ----------------------------------------------------------

test('sweeping twice in immediate succession does not escalate twice', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $lead = Lead::factory()->assignedTo($first, priority: 1)->assignedTo($second, priority: 2)->create();
    backdateTier($lead, 1, now()->subDays(10));

    expect(sweepEscalations())->toBe(1)
        ->and(sweepEscalations())->toBe(0);
});

// -- What the next tier receives ---------------------------------------------------

test('the next tier is told, not the tier that just ended', function () {
    $stale = User::factory()->create(['email_verified_at' => now()]);
    $next = User::factory()->create(['email_verified_at' => now()]);

    $lead = Lead::factory()->assignedTo($stale, priority: 1)->assignedTo($next, priority: 2)->create();
    backdateTier($lead, 1, now()->subDays(10));

    sweepEscalations();

    expect($next->unreadNotifications()->count())->toBe(1)
        ->and($stale->unreadNotifications()->count())->toBe(0);
});

test('every assignee sharing the next tier is told', function () {
    $stale = User::factory()->create();
    $nextA = User::factory()->create(['email_verified_at' => now()]);
    $nextB = User::factory()->create(['email_verified_at' => now()]);

    $lead = Lead::factory()
        ->assignedTo($stale, priority: 1)
        ->assignedTo($nextA, priority: 2)
        ->assignedTo($nextB, priority: 2)
        ->create();

    backdateTier($lead, 1, now()->subDays(10));

    sweepEscalations();

    expect($nextA->unreadNotifications()->count())->toBe(1)
        ->and($nextB->unreadNotifications()->count())->toBe(1);
});

// -- The command --------------------------------------------------------------------

test('the scheduled command runs the sweep', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $lead = Lead::factory()->assignedTo($first, priority: 1)->assignedTo($second, priority: 2)->create();
    backdateTier($lead, 1, now()->subDays(10));

    $this->artisan('leads:escalate-assignments')
        ->expectsOutputToContain('1 lead escalated')
        ->assertSuccessful();
});

test('the escalation sweep is scheduled', function () {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($event) => $event->command ?? '')
        ->implode(' ');

    expect($commands)->toContain('leads:escalate-assignments');
});
