<?php

use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\NotificationEventRegistry;
use App\Domain\Notifications\NotificationMatrix;
use App\Domain\Support\Actions\AddTicketCommentAction;
use App\Domain\Support\Actions\ApplySlaPolicyAction;
use App\Domain\Support\Actions\ChangeTicketStatusAction;
use App\Domain\Support\Actions\CreateTicketAction;
use App\Domain\Support\Actions\EscalateTicketAction;
use App\Domain\Support\Actions\SweepSlaAction;
use App\Domain\Support\Actions\UpdateTicketAction;
use App\Domain\Support\DTOs\TicketData;
use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\SlaPolicy;
use App\Domain\Support\Models\Ticket;
use App\Domain\Support\SlaClock;
use App\Domain\Support\TicketMergeData;
use App\Livewire\Support\SlaPolicies;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * A ticket raised at a known moment, under a policy that promises the given
 * number of minutes at every priority.
 *
 * The clock runs from `created_at`, so a fixture that did not control it would
 * be testing whatever the factory happened to stamp.
 */
function slaTicket(
    ?int $responseMinutes = 60,
    ?int $resolutionMinutes = 240,
    string $raisedAt = '2026-09-13 09:00:00',
): Ticket {
    $policy = SlaPolicy::factory()->default()->promising($responseMinutes, $resolutionMinutes)->create();

    Carbon::setTestNow($raisedAt);

    $ticket = app(CreateTicketAction::class)(
        TicketData::fromArray([
            'subject' => 'Printer will not connect',
            'owner_id' => (string) ticketAdmin()->id,
        ]),
        ticketAdmin(),
    );

    expect($ticket->sla_policy_id)->toBe($policy->id);

    return $ticket->fresh();
}

beforeEach(function () {
    Cache::flush();
    app(NotificationMatrix::class)->flush();
    Queue::fake();
    Carbon::setTestNow('2026-09-13 09:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

// -- Starting the clock --------------------------------------------------------

test('a new ticket is given the default policy and its deadlines', function () {
    $ticket = slaTicket(responseMinutes: 60, resolutionMinutes: 240);

    expect($ticket->first_response_due_at->format('Y-m-d H:i'))->toBe('2026-09-13 10:00')
        ->and($ticket->resolution_due_at->format('Y-m-d H:i'))->toBe('2026-09-13 13:00');
});

test('the deadlines run from when the ticket arrived, not from now', function () {
    SlaPolicy::factory()->default()->promising(60, 240)->create();

    $ticket = Ticket::factory()->ownedBy(ticketAdmin())->create(['created_at' => '2026-09-13 08:00:00']);

    Carbon::setTestNow('2026-09-13 11:00:00');
    app(ApplySlaPolicyAction::class)($ticket);

    // Otherwise a desk buys itself an hour by applying the policy late.
    expect($ticket->fresh()->first_response_due_at->format('Y-m-d H:i'))->toBe('2026-09-13 09:00');
});

test('a ticket raised with no default policy runs without a clock', function () {
    $ticket = Ticket::factory()->ownedBy(ticketAdmin())->create();

    expect(app(ApplySlaPolicyAction::class)($ticket))->toBeFalse()
        ->and($ticket->fresh()->resolution_due_at)->toBeNull();
});

test('an inactive policy is not the default, even when it says it is', function () {
    SlaPolicy::factory()->default()->inactive()->promising(60, 240)->create();

    expect(app(ApplySlaPolicyAction::class)->defaultPolicy())->toBeNull();
});

test('a policy that promises nothing at this priority still becomes the ticket policy', function () {
    $policy = SlaPolicy::factory()->default()->create();
    $policy->targets()->create([
        'priority' => TicketPriority::Urgent->value,
        'first_response_minutes' => 30,
        'resolution_minutes' => 60,
    ]);

    $ticket = Ticket::factory()->ownedBy(ticketAdmin())->withPriority(TicketPriority::Low)->create();

    expect(app(ApplySlaPolicyAction::class)($ticket))->toBeFalse();

    // The policy is remembered so a later retriage is measured against it.
    expect($ticket->fresh()->sla_policy_id)->toBe($policy->id)
        ->and($ticket->fresh()->resolution_due_at)->toBeNull();
});

test('a null target minute means no promise, not nought minutes', function () {
    $ticket = slaTicket(responseMinutes: 60, resolutionMinutes: null);

    expect($ticket->first_response_due_at)->not->toBeNull()
        ->and($ticket->resolution_due_at)->toBeNull()
        ->and(app(SlaClock::class)->minutesRemaining($ticket, SlaClock::RESOLUTION))->toBeNull();
});

// -- The clock -----------------------------------------------------------------

test('the clock counts down', function () {
    $ticket = slaTicket(resolutionMinutes: 240);

    Carbon::setTestNow('2026-09-13 10:00:00');

    expect(app(SlaClock::class)->minutesRemaining($ticket, SlaClock::RESOLUTION))->toBe(180.0);
});

test('the clock goes negative once the deadline has gone', function () {
    $ticket = slaTicket(resolutionMinutes: 240);

    Carbon::setTestNow('2026-09-13 14:00:00');

    expect(app(SlaClock::class)->minutesRemaining($ticket, SlaClock::RESOLUTION))->toBe(-60.0)
        ->and(app(SlaClock::class)->hasBreached($ticket, SlaClock::RESOLUTION))->toBeTrue();
});

test('a resolved ticket is owed nothing more', function () {
    $ticket = slaTicket(resolutionMinutes: 240);

    Carbon::setTestNow('2026-09-13 10:00:00');
    app(ChangeTicketStatusAction::class)($ticket, TicketStatus::Resolved);

    Carbon::setTestNow('2026-09-14 10:00:00');

    // A settled ticket cannot breach afterwards, however long it sits.
    expect(app(SlaClock::class)->hasBreached($ticket->fresh(), SlaClock::RESOLUTION))->toBeFalse()
        ->and(app(SlaClock::class)->minutesRemaining($ticket->fresh(), SlaClock::RESOLUTION))->toBeNull();
});

// -- Pause on hold -------------------------------------------------------------

test('putting a ticket on hold stops the clock', function () {
    $ticket = slaTicket(resolutionMinutes: 240);

    Carbon::setTestNow('2026-09-13 10:00:00');
    app(ChangeTicketStatusAction::class)($ticket, TicketStatus::OnHold);

    Carbon::setTestNow('2026-09-13 18:00:00');

    // Eight hours later and it still has the three hours it had when the hold
    // began.
    expect(app(SlaClock::class)->minutesRemaining($ticket->fresh(), SlaClock::RESOLUTION))->toBe(180.0)
        ->and(app(SlaClock::class)->hasBreached($ticket->fresh(), SlaClock::RESOLUTION))->toBeFalse();
});

test('coming off hold pushes the deadline out by however long the hold lasted', function () {
    $ticket = slaTicket(resolutionMinutes: 240);

    Carbon::setTestNow('2026-09-13 10:00:00');
    app(ChangeTicketStatusAction::class)($ticket, TicketStatus::OnHold);

    Carbon::setTestNow('2026-09-13 12:00:00');
    app(ChangeTicketStatusAction::class)($ticket->fresh(), TicketStatus::Open);

    $ticket = $ticket->fresh();

    expect($ticket->resolution_due_at->format('Y-m-d H:i'))->toBe('2026-09-13 15:00')
        ->and($ticket->sla_paused_seconds)->toBe(7200)
        ->and($ticket->sla_paused_at)->toBeNull()
        // The promise is the same four hours of our time it always was.
        ->and(app(SlaClock::class)->minutesRemaining($ticket, SlaClock::RESOLUTION))->toBe(180.0);
});

test('waiting on the customer does not pause the clock', function () {
    $ticket = slaTicket(resolutionMinutes: 240);

    Carbon::setTestNow('2026-09-13 10:00:00');
    app(ChangeTicketStatusAction::class)($ticket, TicketStatus::Pending);

    Carbon::setTestNow('2026-09-13 14:00:00');

    // Pending is theirs, on hold is ours. A desk that paused on pending could
    // stop every clock by asking a question.
    expect($ticket->fresh()->sla_paused_at)->toBeNull()
        ->and(app(SlaClock::class)->hasBreached($ticket->fresh(), SlaClock::RESOLUTION))->toBeTrue();
});

test('a held ticket is not swept', function () {
    $ticket = slaTicket(resolutionMinutes: 60);

    Carbon::setTestNow('2026-09-13 09:30:00');
    app(ChangeTicketStatusAction::class)($ticket, TicketStatus::OnHold);

    Carbon::setTestNow('2026-09-14 09:00:00');
    $result = app(SweepSlaAction::class)();

    expect($result)->toBe(['warned' => 0, 'breached' => 0])
        ->and($ticket->fresh()->resolution_breached_at)->toBeNull();
});

// -- First response ------------------------------------------------------------

test('a reply to the customer stops the first-response clock', function () {
    $ticket = slaTicket(responseMinutes: 60);
    $agent = ticketAdmin();

    Carbon::setTestNow('2026-09-13 09:30:00');
    app(AddTicketCommentAction::class)($ticket, 'We are looking at it.', $agent);

    expect($ticket->fresh()->first_responded_at->format('Y-m-d H:i'))->toBe('2026-09-13 09:30')
        ->and(app(SlaClock::class)->owesResponse($ticket->fresh()))->toBeFalse();
});

test('an internal note is not an answer', function () {
    $ticket = slaTicket(responseMinutes: 60);

    app(AddTicketCommentAction::class)($ticket, 'Third time this month.', ticketAdmin(), internal: true);

    // The customer never saw it, so it cannot count as having replied to them.
    expect($ticket->fresh()->first_responded_at)->toBeNull();
});

test('the customer writing again is not an answer', function () {
    $ticket = slaTicket(responseMinutes: 60);

    app(AddTicketCommentAction::class)(
        ticket: $ticket,
        body: 'Any news?',
        author: null,
        fromCustomer: true,
        authorName: 'Dana',
    );

    expect($ticket->fresh()->first_responded_at)->toBeNull();
});

test('the second reply does not move the first-response time', function () {
    $ticket = slaTicket(responseMinutes: 60);
    $agent = ticketAdmin();

    Carbon::setTestNow('2026-09-13 09:10:00');
    app(AddTicketCommentAction::class)($ticket, 'Looking at it.', $agent);

    Carbon::setTestNow('2026-09-13 09:50:00');
    app(AddTicketCommentAction::class)($ticket->fresh(), 'Still looking.', $agent);

    expect($ticket->fresh()->first_responded_at->format('H:i'))->toBe('09:10');
});

// -- Warnings and breaches -----------------------------------------------------

test('the sweep warns once the warning point is reached', function () {
    $ticket = slaTicket(responseMinutes: null, resolutionMinutes: 100);

    // 80% of 100 minutes.
    Carbon::setTestNow('2026-09-13 10:20:00');
    $result = app(SweepSlaAction::class)();

    expect($result['warned'])->toBe(1)
        ->and($ticket->fresh()->resolution_warned_at)->not->toBeNull()
        ->and(NotificationLog::query()->where('event', 'ticket.sla_warning')->count())->toBeGreaterThan(0);
});

test('a warning is sent once, however often the sweep runs', function () {
    slaTicket(responseMinutes: null, resolutionMinutes: 100);

    Carbon::setTestNow('2026-09-13 10:20:00');
    app(SweepSlaAction::class)();

    Carbon::setTestNow('2026-09-13 10:21:00');
    $again = app(SweepSlaAction::class)();

    // The stamp on the ticket is what stops a minute-by-minute sweep sending
    // the same warning fourteen hundred times.
    expect($again['warned'])->toBe(0);
});

test('the warning point moves with the length of the promise', function () {
    // One policy, not two: slaTicket() creates its own default, and a second
    // default would make "the default" whichever came first.
    SlaPolicy::factory()->default()->warningAt(50)->promising(null, 240)->create();

    Carbon::setTestNow('2026-09-13 09:00:00');
    $ticket = app(CreateTicketAction::class)(
        TicketData::fromArray(['subject' => 'Printer', 'owner_id' => (string) ticketAdmin()->id]),
        ticketAdmin(),
    );

    // Half of four hours, not a fixed number of minutes.
    expect(app(SlaClock::class)->warnAt($ticket->fresh(), SlaClock::RESOLUTION)->format('H:i'))->toBe('11:00');
});

test('the sweep records a breach and escalates the ticket', function () {
    $ticket = slaTicket(responseMinutes: null, resolutionMinutes: 60);
    expect($ticket->priority())->toBe(TicketPriority::Normal);

    Carbon::setTestNow('2026-09-13 10:30:00');
    $result = app(SweepSlaAction::class)();

    $ticket = $ticket->fresh();

    expect($result['breached'])->toBe(1)
        ->and($ticket->resolution_breached_at)->not->toBeNull()
        ->and($ticket->escalated_at)->not->toBeNull()
        ->and($ticket->priority())->toBe(TicketPriority::High)
        ->and(NotificationLog::query()->where('event', 'ticket.sla_breached')->count())->toBeGreaterThan(0);
});

test('a breach tells the agent and the administrators', function () {
    $agent = ticketAdmin();
    ticketAdmin();
    $policy = SlaPolicy::factory()->default()->promising(null, 60)->create();

    Carbon::setTestNow('2026-09-13 09:00:00');
    $ticket = Ticket::factory()->ownedBy($agent)->create(['created_at' => '2026-09-13 09:00:00']);
    app(ApplySlaPolicyAction::class)($ticket, $policy);

    Carbon::setTestNow('2026-09-13 10:30:00');
    app(SweepSlaAction::class)();

    $types = NotificationLog::query()->where('event', 'ticket.sla_breached')->pluck('recipient_type')->unique()->all();

    expect($types)->toContain('assigned_agent')->toContain('admin')
        // Never the customer: telling them we missed the promise is a
        // conversation, not a notification.
        ->and($types)->not->toContain('customer');
});

test('escalation happens once, not once a minute', function () {
    $ticket = slaTicket(responseMinutes: null, resolutionMinutes: 60);

    Carbon::setTestNow('2026-09-13 10:30:00');
    app(EscalateTicketAction::class)($ticket);
    $first = $ticket->fresh()->priority();

    expect(app(EscalateTicketAction::class)($ticket->fresh()))->toBeFalse()
        ->and($ticket->fresh()->priority())->toBe($first);
});

test('escalating an urgent ticket leaves it urgent', function () {
    $ticket = slaTicket(responseMinutes: null, resolutionMinutes: 60);
    $ticket->forceFill(['priority' => TicketPriority::Urgent->value])->save();

    app(EscalateTicketAction::class)($ticket->fresh());

    // There is nowhere above urgent, and wrapping round to low would be worse
    // than standing still.
    expect($ticket->fresh()->priority())->toBe(TicketPriority::Urgent);
});

test('a breach subsumes the warning it never got', function () {
    $ticket = slaTicket(responseMinutes: null, resolutionMinutes: 60);

    // The scheduler was down through the warning point.
    Carbon::setTestNow('2026-09-13 11:00:00');
    $result = app(SweepSlaAction::class)();

    expect($result)->toBe(['warned' => 0, 'breached' => 1])
        ->and($ticket->fresh()->resolution_warned_at)->toBeNull();
});

test('a ticket that was answered in time never breaches its response promise', function () {
    $ticket = slaTicket(responseMinutes: 60, resolutionMinutes: null);

    Carbon::setTestNow('2026-09-13 09:30:00');
    app(AddTicketCommentAction::class)($ticket, 'We are on it.', ticketAdmin());

    Carbon::setTestNow('2026-09-13 12:00:00');
    app(SweepSlaAction::class)();

    expect($ticket->fresh()->response_breached_at)->toBeNull();
});

// -- Retriage ------------------------------------------------------------------

test('retriage re-cuts the promise from when the ticket arrived', function () {
    $policy = SlaPolicy::factory()->default()->create();

    foreach ([[TicketPriority::Normal, 480], [TicketPriority::Urgent, 60]] as [$priority, $minutes]) {
        $policy->targets()->create([
            'priority' => $priority->value,
            'first_response_minutes' => null,
            'resolution_minutes' => $minutes,
        ]);
    }

    Carbon::setTestNow('2026-09-13 09:00:00');
    $ticket = app(CreateTicketAction::class)(
        TicketData::fromArray(['subject' => 'Printer', 'owner_id' => (string) ticketAdmin()->id]),
        ticketAdmin(),
    );

    expect($ticket->fresh()->resolution_due_at->format('H:i'))->toBe('17:00');

    Carbon::setTestNow('2026-09-13 11:00:00');
    app(UpdateTicketAction::class)($ticket->fresh(), TicketData::fromArray([
        'subject' => 'Printer',
        'owner_id' => (string) $ticket->owner_id,
        'priority' => (string) TicketPriority::Urgent->value,
    ]));

    // Ten o'clock, an hour from when it came in — not from the retriage, which
    // would hand the desk another hour for changing a dropdown.
    expect($ticket->fresh()->resolution_due_at->format('H:i'))->toBe('10:00');
});

// -- The events ----------------------------------------------------------------

test('the two SLA events are registered and name only fields the data supplies', function (string $key) {
    $event = NotificationEventRegistry::find($key);

    expect($event)->not->toBeNull();

    foreach (array_keys($event->mergeFields) as $field) {
        expect(TicketMergeData::slaFields())->toHaveKey($field);
    }
})->with(['ticket.sla_warning', 'ticket.sla_breached']);

test('the breach message carries the promise, the policy and how late it is', function () {
    $ticket = slaTicket(responseMinutes: null, resolutionMinutes: 60);

    Carbon::setTestNow('2026-09-13 10:30:00');
    $data = TicketMergeData::forSla($ticket->fresh(), SlaClock::RESOLUTION);

    expect($data['sla']['promise'])->toBe('resolution')
        ->and($data['sla']['policy'])->toBe('Standard support')
        ->and($data['sla']['due'])->toContain('10:00')
        ->and($data['sla']['remaining'])->toContain('overdue');
});

// -- The settings screen -------------------------------------------------------

test('the policies screen needs its own permission', function () {
    Livewire::actingAs(ticketAdmin())
        ->test(SlaPolicies::class)
        ->assertForbidden();

    // An agent working a queue does not get to change the deadline they are
    // being measured against.
    Livewire::actingAs(ticketUser(['tickets.sla']))
        ->test(SlaPolicies::class)
        ->assertOk();
});

test('the screen saves a policy and its targets', function () {
    Livewire::actingAs(ticketUser(['tickets.sla']))
        ->test(SlaPolicies::class)
        ->call('create')
        ->set('name', 'Gold')
        ->set('warnAtPercent', '75')
        ->set('targets.'.TicketPriority::Urgent->value.'.first_response', '15')
        ->set('targets.'.TicketPriority::Urgent->value.'.resolution', '120')
        ->call('save')
        ->assertHasNoErrors();

    $policy = SlaPolicy::query()->with('targets')->firstOrFail();

    expect($policy->name)->toBe('Gold')
        ->and($policy->warn_at_percent)->toBe(75)
        ->and($policy->targets)->toHaveCount(1)
        ->and($policy->targetFor(TicketPriority::Urgent)->first_response_minutes)->toBe(15);
});

test('an empty pair of boxes stores no target at all', function () {
    Livewire::actingAs(ticketUser(['tickets.sla']))
        ->test(SlaPolicies::class)
        ->call('create')
        ->set('name', 'Bare')
        ->call('save')
        ->assertHasNoErrors();

    // Not four rows of nulls somebody has to read as "nothing".
    expect(SlaPolicy::query()->firstOrFail()->targets)->toHaveCount(0);
});

test('a warning point outside the window is refused', function (string $percent) {
    Livewire::actingAs(ticketUser(['tickets.sla']))
        ->test(SlaPolicies::class)
        ->call('create')
        ->set('name', 'Gold')
        ->set('warnAtPercent', $percent)
        ->call('save')
        ->assertHasErrors(['warnAtPercent']);
})->with(['zero' => ['0'], 'hundred' => ['100']]);

test('making one policy the default takes it off the others', function () {
    $first = SlaPolicy::factory()->default()->create(['name' => 'First']);
    $second = SlaPolicy::factory()->create(['name' => 'Second']);

    Livewire::actingAs(ticketUser(['tickets.sla']))
        ->test(SlaPolicies::class)
        ->call('makeDefault', $second->id);

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue();
});

test('removing a policy leaves the deadlines it already promised', function () {
    $ticket = slaTicket(resolutionMinutes: 240);
    $due = $ticket->resolution_due_at;

    Livewire::actingAs(ticketUser(['tickets.sla']))
        ->test(SlaPolicies::class)
        ->call('delete', $ticket->sla_policy_id);

    // A promise already made does not stop existing because somebody tidied
    // the list.
    expect($ticket->fresh()->resolution_due_at->format('Y-m-d H:i'))->toBe($due->format('Y-m-d H:i'));
});
