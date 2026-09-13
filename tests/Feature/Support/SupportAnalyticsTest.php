<?php

use App\Domain\Support\Analytics\AgentPerformance;
use App\Domain\Support\Analytics\SupportMetrics;
use App\Domain\Support\Analytics\SupportPeriod;
use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketSource;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\Ticket;
use App\Livewire\Support\SupportAnalytics;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * A ticket with its whole life written down at once.
 *
 * Every figure here is a duration between two stamps, so a fixture that let the
 * factory choose them would be testing the clock rather than the arithmetic.
 */
function analyticsTicket(
    User $agent,
    string $raisedAt,
    ?string $resolvedAt = null,
    ?string $firstRepliedAt = null,
    int $pausedSeconds = 0,
    bool $breached = false,
    TicketPriority $priority = TicketPriority::Normal,
    TicketSource $source = TicketSource::Manual,
): Ticket {
    $ticket = Ticket::factory()->ownedBy($agent)->withPriority($priority)->from($source)->create([
        'created_at' => $raisedAt,
    ]);

    $ticket->forceFill([
        'status' => $resolvedAt === null ? TicketStatus::Open->value : TicketStatus::Resolved->value,
        'resolved_at' => $resolvedAt,
        'first_responded_at' => $firstRepliedAt,
        'sla_paused_seconds' => $pausedSeconds,
        'resolution_breached_at' => $breached ? $resolvedAt : null,
        'resolution_due_at' => $resolvedAt === null ? null : $raisedAt,
    ])->save();

    return $ticket->fresh();
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-30 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

// -- The window ----------------------------------------------------------------

test('a window covers whole days at both ends', function () {
    $period = SupportPeriod::days(7);

    expect($period->from->format('Y-m-d H:i:s'))->toBe('2026-09-24 00:00:00')
        ->and($period->to->format('Y-m-d H:i:s'))->toBe('2026-09-30 23:59:59')
        ->and($period->dayCount())->toBe(7);
});

test('a window the URL does not recognise falls back rather than failing', function () {
    // A hand-edited query string should show a sensible page, not an error.
    expect(SupportPeriod::fromKey('nonsense')->dayCount())->toBe(30);
});

test('this month and last month are whole months', function () {
    expect(SupportPeriod::thisMonth()->from->format('Y-m-d'))->toBe('2026-09-01')
        ->and(SupportPeriod::lastMonth()->from->format('Y-m-d'))->toBe('2026-08-01')
        ->and(SupportPeriod::lastMonth()->to->format('Y-m-d'))->toBe('2026-08-31');
});

// -- Volume --------------------------------------------------------------------

test('volume counts what came in and what went out separately', function () {
    $agent = ticketAdmin();

    // Raised inside the window, resolved inside it.
    analyticsTicket($agent, '2026-09-25 09:00:00', '2026-09-25 12:00:00');
    // Raised before the window, resolved inside it: counts as resolved only.
    analyticsTicket($agent, '2026-08-01 09:00:00', '2026-09-26 09:00:00');
    // Raised inside, still open: counts as raised only.
    analyticsTicket($agent, '2026-09-27 09:00:00');

    $volume = app(SupportMetrics::class)->volume(SupportPeriod::days(7), $agent);

    // "How much came in" and "how much did we get through" are different
    // questions, and a ticket can answer both, one or neither.
    expect($volume['raised'])->toBe(2)
        ->and($volume['resolved'])->toBe(2)
        ->and($volume['open_now'])->toBe(1);
});

test('volume only counts tickets the viewer may see', function () {
    $mine = ticketUser();
    analyticsTicket($mine, '2026-09-25 09:00:00');
    analyticsTicket(ticketAdmin(), '2026-09-25 09:00:00');

    // A screen must never report a number drawn from records the viewer could
    // not open one by one.
    expect(app(SupportMetrics::class)->volume(SupportPeriod::days(7), $mine)['raised'])->toBe(1);
});

test('the daily series has a bar for every day, including the empty ones', function () {
    $agent = ticketAdmin();
    analyticsTicket($agent, '2026-09-28 09:00:00');
    analyticsTicket($agent, '2026-09-28 11:00:00');
    analyticsTicket($agent, '2026-09-30 09:00:00');

    $days = app(SupportMetrics::class)->volumeByDay(SupportPeriod::days(7), $agent);

    // A chart that omitted them would draw a quiet fortnight as a straight line
    // between two busy ones.
    expect($days)->toHaveCount(7)
        ->and($days['2026-09-28'])->toBe(2)
        ->and($days['2026-09-29'])->toBe(0)
        ->and($days['2026-09-30'])->toBe(1);
});

// -- Resolution time -----------------------------------------------------------

test('resolution time is measured net of time on hold', function () {
    $agent = ticketAdmin();

    // Four hours wall-clock, one of them on hold.
    analyticsTicket($agent, '2026-09-25 09:00:00', '2026-09-25 13:00:00', pausedSeconds: 3600);

    $resolution = app(SupportMetrics::class)->resolutionTime(SupportPeriod::days(7), $agent);

    // The SLA clock pauses on hold, so this must too — a screen that disagreed
    // with the clock would have somebody arguing about which figure was real.
    expect($resolution['average'])->toBe(3.0)
        ->and($resolution['median'])->toBe(3.0)
        ->and($resolution['count'])->toBe(1);
});

test('a hold longer than the whole ticket does not produce a negative time', function () {
    $agent = ticketAdmin();
    analyticsTicket($agent, '2026-09-25 09:00:00', '2026-09-25 10:00:00', pausedSeconds: 99999);

    // sla_paused_seconds is UNSIGNED, so without the CAST the subtraction
    // promotes to BIGINT UNSIGNED and MySQL fails the whole query with error
    // 1690 rather than clamping — GREATEST runs too late to save it.
    expect(app(SupportMetrics::class)->resolutionTime(SupportPeriod::days(7), $agent)['average'])->toBe(0.0);
});

test('the median is the typical ticket and the average is not', function () {
    $agent = ticketAdmin();

    // Three quick ones and one that sat over a weekend.
    analyticsTicket($agent, '2026-09-25 09:00:00', '2026-09-25 10:00:00');
    analyticsTicket($agent, '2026-09-25 09:00:00', '2026-09-25 11:00:00');
    analyticsTicket($agent, '2026-09-25 09:00:00', '2026-09-25 12:00:00');
    analyticsTicket($agent, '2026-09-25 09:00:00', '2026-09-29 09:00:00');

    $resolution = app(SupportMetrics::class)->resolutionTime(SupportPeriod::days(7), $agent);

    // 1, 2, 3 and 96 hours: the median says three quarters of these took a
    // couple of hours, and the mean says something untrue about all four.
    expect($resolution['median'])->toBe(2.5)
        ->and($resolution['average'])->toBe(25.5);
});

test('the median of an even number of tickets is the middle pair', function () {
    $agent = ticketAdmin();
    analyticsTicket($agent, '2026-09-25 09:00:00', '2026-09-25 10:00:00');
    analyticsTicket($agent, '2026-09-25 09:00:00', '2026-09-25 12:00:00');

    // Not one of the two: a two-ticket month has no single typical ticket.
    expect(app(SupportMetrics::class)->resolutionTime(SupportPeriod::days(7), $agent)['median'])->toBe(2.0);
});

test('an empty window reports nothing rather than nought', function () {
    $resolution = app(SupportMetrics::class)->resolutionTime(SupportPeriod::days(7), ticketAdmin());

    // Nought hours to resolve would be a remarkable claim.
    expect($resolution['count'])->toBe(0)
        ->and($resolution['average'])->toBeNull()
        ->and($resolution['median'])->toBeNull()
        ->and($resolution['within_sla'])->toBeNull();
});

test('SLA attainment counts only tickets that were given a promise', function () {
    $agent = ticketAdmin();

    analyticsTicket($agent, '2026-09-25 09:00:00', '2026-09-25 10:00:00');
    analyticsTicket($agent, '2026-09-25 09:00:00', '2026-09-25 11:00:00');
    analyticsTicket($agent, '2026-09-25 09:00:00', '2026-09-26 09:00:00', breached: true);

    // A ticket with no due time is not in either half: a desk running without a
    // policy has not met 100% of nothing.
    $noPromise = analyticsTicket($agent, '2026-09-25 09:00:00', '2026-09-25 10:00:00');
    $noPromise->forceFill(['resolution_due_at' => null])->save();

    expect(app(SupportMetrics::class)->resolutionTime(SupportPeriod::days(7), $agent)['within_sla'])
        ->toBe(66.7);
});

// -- First response ------------------------------------------------------------

test('first response is measured wall-clock from arrival', function () {
    $agent = ticketAdmin();

    // Ninety minutes to answer, with an hour of hold in the ticket's later life.
    analyticsTicket($agent, '2026-09-25 09:00:00', '2026-09-26 09:00:00', '2026-09-25 10:30:00', pausedSeconds: 3600);

    $response = app(SupportMetrics::class)->firstResponseTime(SupportPeriod::days(7), $agent);

    // A hold before we have answered at all is itself a failure to answer, and
    // subtracting the ticket's whole pause total would over-credit a desk for
    // holds that happened after the reply.
    expect($response['average'])->toBe(90.0)
        ->and($response['count'])->toBe(1);
});

test('tickets still waiting for a first reply are counted beside the average, not in it', function () {
    $agent = ticketAdmin();

    analyticsTicket($agent, '2026-09-25 09:00:00', null, '2026-09-25 09:01:00');
    analyticsTicket($agent, '2026-09-25 09:00:00');
    analyticsTicket($agent, '2026-09-25 09:00:00');

    $response = app(SupportMetrics::class)->firstResponseTime(SupportPeriod::days(7), $agent);

    // Ten answered in a minute and forty ignored is a wonderful average.
    expect($response['average'])->toBe(1.0)
        ->and($response['count'])->toBe(1)
        ->and($response['unanswered'])->toBe(2);
});

// -- Agent performance ---------------------------------------------------------

test('each agent gets a row with what they got through', function () {
    $manager = ticketAdmin();
    $dana = ticketAdmin();
    $sam = ticketAdmin();

    analyticsTicket($dana, '2026-09-25 09:00:00', '2026-09-25 11:00:00');
    analyticsTicket($dana, '2026-09-25 09:00:00', '2026-09-25 13:00:00', breached: true);
    analyticsTicket($dana, '2026-09-26 09:00:00');
    analyticsTicket($sam, '2026-09-25 09:00:00', '2026-09-25 10:00:00');

    $rows = collect(app(SupportMetrics::class)->agentPerformance(SupportPeriod::days(7), $manager))
        ->keyBy('agentId');

    expect($rows[$dana->id]->resolved)->toBe(2)
        ->and($rows[$dana->id]->stillOpen)->toBe(1)
        ->and($rows[$dana->id]->averageResolutionHours)->toBe(3.0)
        ->and($rows[$dana->id]->breached)->toBe(1)
        ->and($rows[$dana->id]->breachRate())->toBe(50.0)
        ->and($rows[$sam->id]->resolved)->toBe(1)
        ->and($rows[$sam->id]->breached)->toBe(0);
});

test('an agent who resolved nothing but is holding tickets is still on the list', function () {
    $manager = ticketAdmin();
    $busy = ticketAdmin();
    analyticsTicket($busy, '2026-09-26 09:00:00');

    $rows = app(SupportMetrics::class)->agentPerformance(SupportPeriod::days(7), $manager);

    // That is the row worth seeing.
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->resolved)->toBe(0)
        ->and($rows[0]->stillOpen)->toBe(1)
        ->and($rows[0]->averageResolutionHours)->toBeNull()
        ->and($rows[0]->breachRate())->toBeNull();
});

test('the agent table is ordered by what each got through', function () {
    $manager = ticketAdmin();
    $few = ticketAdmin();
    $many = ticketAdmin();

    analyticsTicket($few, '2026-09-25 09:00:00', '2026-09-25 10:00:00');

    foreach (range(1, 3) as $ignored) {
        analyticsTicket($many, '2026-09-25 09:00:00', '2026-09-25 10:00:00');
    }

    $rows = app(SupportMetrics::class)->agentPerformance(SupportPeriod::days(7), $manager);

    expect($rows[0]->agentId)->toBe($many->id)
        ->and($rows[0]->resolved)->toBe(3);
});

test('agent rows respect the viewer access level too', function () {
    $mine = ticketUser();
    analyticsTicket($mine, '2026-09-25 09:00:00', '2026-09-25 10:00:00');
    analyticsTicket(ticketAdmin(), '2026-09-25 09:00:00', '2026-09-25 10:00:00');

    $rows = app(SupportMetrics::class)->agentPerformance(SupportPeriod::days(7), $mine);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->agentId)->toBe($mine->id);
});

// -- Breakdown -----------------------------------------------------------------

test('the breakdown lists every bucket, including the empty ones', function () {
    $agent = ticketAdmin();

    analyticsTicket($agent, '2026-09-25 09:00:00', priority: TicketPriority::Urgent, source: TicketSource::Email);
    analyticsTicket($agent, '2026-09-25 09:00:00', priority: TicketPriority::Urgent, source: TicketSource::Phone);

    $breakdown = app(SupportMetrics::class)->breakdown(SupportPeriod::days(7), $agent);

    // "Nothing came in by chat" is a useful thing to be told, and a list that
    // hid it would redraw itself month to month.
    expect($breakdown['priority'])->toHaveKey('Low')
        ->and($breakdown['priority']['Low'])->toBe(0)
        ->and($breakdown['priority']['Urgent'])->toBe(2)
        ->and($breakdown['source']['Email'])->toBe(1)
        ->and($breakdown['source']['Chat'])->toBe(0);
});

// -- The screen ----------------------------------------------------------------

test('the analytics screen has its own permission', function () {
    // Working a queue and reporting across the whole desk are different jobs.
    Livewire::actingAs(ticketUser())
        ->test(SupportAnalytics::class)
        ->assertForbidden();

    Livewire::actingAs(ticketUser(['tickets.view', 'tickets.analytics']))
        ->test(SupportAnalytics::class)
        ->assertOk();
});

test('the screen shows the figures it was given', function () {
    $agent = ticketUser(['tickets.view', 'tickets.analytics']);

    analyticsTicket($agent, '2026-09-25 09:00:00', '2026-09-25 11:00:00', '2026-09-25 09:30:00');

    Livewire::actingAs($agent)
        ->test(SupportAnalytics::class)
        ->assertOk()
        ->assertSee('Time to resolve')
        ->assertSee('Time to first reply')
        ->assertSee('By agent')
        ->assertSee($agent->name);
});

test('changing the window redraws every figure', function () {
    $agent = ticketUser(['tickets.view', 'tickets.analytics']);

    analyticsTicket($agent, '2026-07-10 09:00:00', '2026-07-10 10:00:00');

    $screen = Livewire::actingAs($agent)->test(SupportAnalytics::class);

    expect($screen->instance()->volume()['raised'])->toBe(0);

    // A stale figure beside a fresh one is the worst outcome on a page like
    // this, so they are all dropped together.
    $screen->set('periodKey', '90');

    expect($screen->instance()->volume()['raised'])->toBe(1);
});

test('an empty window still draws a chart rather than dividing by zero', function () {
    $agent = ticketUser(['tickets.view', 'tickets.analytics']);

    Livewire::actingAs($agent)
        ->test(SupportAnalytics::class)
        ->assertOk();

    expect(Livewire::actingAs($agent)->test(SupportAnalytics::class)->instance()->busiestDay([]))->toBe(1);
});

test('the analytics page is reachable and is not shadowed by a ticket id', function () {
    $agent = ticketUser(['tickets.view', 'tickets.analytics']);

    $this->actingAs($agent)->get(route('tickets.analytics'))->assertOk();
});

test('the durations are formatted for people, not for machines', function () {
    $screen = Livewire::actingAs(ticketUser(['tickets.view', 'tickets.analytics']))
        ->test(SupportAnalytics::class)
        ->instance();

    expect($screen->hours(null))->toBe('—')
        ->and($screen->hours(0.5))->toBe('30 min')
        ->and($screen->hours(3.25))->toBe('3.3 h')
        ->and($screen->hours(96))->toBe('4 d')
        ->and($screen->minutes(90.0))->toBe('1.5 h');
});

test('an agent performance row with nothing resolved has no breach rate', function () {
    $row = new AgentPerformance(
        agentId: 1,
        name: 'Dana',
        resolved: 0,
        stillOpen: 4,
        averageResolutionHours: null,
        breached: 0,
    );

    // Nought per cent of nothing is not a good record.
    expect($row->breachRate())->toBeNull();
});
