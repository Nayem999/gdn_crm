<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Accounts\Models\Account;
use App\Domain\Audit\AuditLogger;
use App\Domain\Contacts\Models\Contact;
use App\Domain\CustomFields\CustomFieldRegistry;
use App\Domain\Shared\Enums\DataAccessLevel;
use App\Domain\Support\Actions\AssignTicketAction;
use App\Domain\Support\Actions\ChangeTicketStatusAction;
use App\Domain\Support\Actions\CreateTicketAction;
use App\Domain\Support\Actions\DeleteTicketAction;
use App\Domain\Support\Actions\UpdateTicketAction;
use App\Domain\Support\DTOs\TicketData;
use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketSource;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\Ticket;
use App\Domain\Timeline\TimelineRegistry;
use App\Domain\Workflows\WorkflowModules;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

/**
 * Somebody who runs the support desk and can see every ticket.
 *
 * @param  array<int, string>  $permissions
 */
function ticketAdmin(?array $permissions = null): User
{
    $permissions ??= [
        'tickets.view', 'tickets.create', 'tickets.update',
        'tickets.assign', 'tickets.delete', 'tickets.export',
        'accounts.view', 'contacts.view',
    ];

    $role = Role::query()->create([
        'name' => 'Support all '.uniqid(),
        'guard_name' => Guard::getDefaultName(Role::class),
        'data_access_level' => DataAccessLevel::All->value,
    ]);

    $role->syncPermissions(PermissionResolver::models($permissions));

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * An agent with named permissions and no role, so their access level is the
 * default — their own records only.
 *
 * @param  array<int, string>  $permissions
 */
function ticketUser(array $permissions = ['tickets.view']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function ticketData(array $overrides = []): TicketData
{
    return TicketData::fromArray([
        'subject' => 'Printer will not connect',
        'description' => 'It worked yesterday.',
        'priority' => (string) TicketPriority::High->value,
        'source' => TicketSource::Email->value,
        ...$overrides,
    ]);
}

function createTicket(?TicketData $data = null, ?User $actor = null): Ticket
{
    $actor ??= ticketAdmin();

    return app(CreateTicketAction::class)(
        $data ?? ticketData(['owner_id' => (string) $actor->id]),
        $actor
    );
}

// -- Creating ------------------------------------------------------------------

test('a ticket is created with what the form carried', function () {
    $actor = ticketAdmin();

    $ticket = createTicket(actor: $actor);

    expect($ticket->subject)->toBe('Printer will not connect')
        ->and($ticket->description)->toBe('It worked yesterday.')
        ->and($ticket->priority())->toBe(TicketPriority::High)
        ->and($ticket->source())->toBe(TicketSource::Email)
        ->and($ticket->owner_id)->toBe($actor->id);
});

test('a new ticket opens as new, whatever was passed in', function () {
    // status is out of $fillable and out of TicketData, so there is no way for
    // a form or a payload to declare a ticket resolved on the way in.
    $ticket = createTicket();

    expect($ticket->status())->toBe(TicketStatus::New)
        ->and($ticket->resolved_at)->toBeNull()
        ->and($ticket->closed_at)->toBeNull();
});

test('a ticket created without an agent belongs to whoever raised it', function () {
    $actor = ticketAdmin();

    $ticket = app(CreateTicketAction::class)(ticketData(), $actor);

    // An unassigned ticket is how a support queue quietly loses a problem.
    expect($ticket->owner_id)->toBe($actor->id);
});

test('a ticket gets a reference derived from its id', function () {
    $ticket = createTicket();

    expect($ticket->number)->toBe(Ticket::PREFIX.str_pad((string) $ticket->id, 5, '0', STR_PAD_LEFT))
        ->and($ticket->reference())->toBe($ticket->number);
});

test('two tickets raised in the same second get different references', function () {
    Carbon::setTestNow('2026-09-13 10:00:00');

    $one = createTicket();
    $two = createTicket();

    // Derived from the id rather than a counter, so there is no race to lose.
    expect($one->number)->not->toBe($two->number);

    Carbon::setTestNow();
});

test('a contact from another account is refused', function () {
    $theirs = Account::factory()->create();
    $ours = Account::factory()->create();
    $contact = Contact::factory()->create(['account_id' => $theirs->id]);

    // Storing it would put the ticket on the wrong customer's history.
    expect(fn () => createTicket(ticketData([
        'owner_id' => (string) User::factory()->create()->id,
        'contact_id' => (string) $contact->id,
        'account_id' => (string) $ours->id,
    ])))->toThrow(RuntimeException::class);

    expect(Ticket::query()->count())->toBe(0);
});

test('a contact at the chosen account is accepted', function () {
    $account = Account::factory()->create();
    $contact = Contact::factory()->create(['account_id' => $account->id]);
    $actor = ticketAdmin();

    $ticket = createTicket(ticketData([
        'owner_id' => (string) $actor->id,
        'contact_id' => (string) $contact->id,
        'account_id' => (string) $account->id,
    ]), $actor);

    expect($ticket->contact_id)->toBe($contact->id)
        ->and($ticket->account_id)->toBe($account->id);
});

test('a relation that is not a real record is refused', function (string $key) {
    expect(fn () => createTicket(ticketData([
        'owner_id' => (string) User::factory()->create()->id,
        $key => '99999',
    ])))->toThrow(RuntimeException::class);
})->with(['contact_id', 'account_id']);

// -- Updating ------------------------------------------------------------------

test('an update changes the details it carries', function () {
    $ticket = createTicket();

    $updated = app(UpdateTicketAction::class)($ticket, ticketData([
        'owner_id' => (string) $ticket->owner_id,
        'subject' => 'Printer connects but prints blank',
        'priority' => (string) TicketPriority::Urgent->value,
    ]));

    expect($updated->subject)->toBe('Printer connects but prints blank')
        ->and($updated->priority())->toBe(TicketPriority::Urgent);
});

test('an update cannot move the status', function () {
    $ticket = createTicket();
    app(ChangeTicketStatusAction::class)($ticket, TicketStatus::Open);

    app(UpdateTicketAction::class)($ticket, ticketData(['owner_id' => (string) $ticket->owner_id]));

    // TicketData has no status field and the column is not fillable, so an
    // edit cannot quietly reopen or resolve anything.
    expect($ticket->fresh()->status())->toBe(TicketStatus::Open);
});

// -- Status --------------------------------------------------------------------

test('moving a ticket writes the status', function () {
    $ticket = createTicket();

    expect(app(ChangeTicketStatusAction::class)($ticket, TicketStatus::Open))->toBeTrue()
        ->and($ticket->fresh()->status())->toBe(TicketStatus::Open);
});

test('a move to the status it is already in reports no move', function () {
    $ticket = createTicket();

    // Not an error, but reporting one would flash a change that did not happen.
    expect(app(ChangeTicketStatusAction::class)($ticket, TicketStatus::New))->toBeFalse();
});

test('resolving and closing both stamp resolved_at', function (TicketStatus $status) {
    Carbon::setTestNow('2026-09-13 11:00:00');
    $ticket = createTicket();

    app(ChangeTicketStatusAction::class)($ticket, $status);

    // A ticket closed without ever being marked resolved was still resolved at
    // that moment; ignoring those would flatter every resolution-time report.
    expect($ticket->fresh()->resolved_at?->format('Y-m-d H:i'))->toBe('2026-09-13 11:00');

    Carbon::setTestNow();
})->with(fn () => [
    'resolved' => [TicketStatus::Resolved],
    'closed' => [TicketStatus::Closed],
]);

test('only closing stamps closed_at', function () {
    $ticket = createTicket();

    app(ChangeTicketStatusAction::class)($ticket, TicketStatus::Resolved);
    expect($ticket->fresh()->closed_at)->toBeNull();

    app(ChangeTicketStatusAction::class)($ticket, TicketStatus::Closed);
    expect($ticket->fresh()->closed_at)->not->toBeNull();
});

test('reopening clears the resolution stamps', function () {
    $ticket = createTicket();
    app(ChangeTicketStatusAction::class)($ticket, TicketStatus::Closed);

    app(ChangeTicketStatusAction::class)($ticket, TicketStatus::Open);

    // A ticket back on somebody's queue that still says it was resolved on
    // Tuesday reads as done, and would be counted as done.
    expect($ticket->fresh()->resolved_at)->toBeNull()
        ->and($ticket->fresh()->closed_at)->toBeNull();
});

test('going from closed back to resolved clears only the closing stamp', function () {
    $ticket = createTicket();
    app(ChangeTicketStatusAction::class)($ticket, TicketStatus::Closed);

    app(ChangeTicketStatusAction::class)($ticket, TicketStatus::Resolved);

    expect($ticket->fresh()->closed_at)->toBeNull()
        ->and($ticket->fresh()->resolved_at)->not->toBeNull();
});

test('the open scope is the statuses still on a queue', function () {
    $user = ticketAdmin();

    foreach (TicketStatus::cases() as $status) {
        Ticket::factory()->ownedBy($user)->withStatus($status)->create();
    }

    expect(Ticket::query()->open()->count())->toBe(count(TicketStatus::openValues()))
        ->and(TicketStatus::openValues())->toBe(['new', 'open', 'pending', 'on_hold']);
});

// -- Assigning -----------------------------------------------------------------

test('assigning hands the ticket to another agent', function () {
    $ticket = createTicket();
    $agent = User::factory()->create();

    expect(app(AssignTicketAction::class)($ticket, $agent))->toBeTrue()
        ->and($ticket->fresh()->owner_id)->toBe($agent->id);
});

test('assigning to whoever already has it reports no change', function () {
    $ticket = createTicket();

    expect(app(AssignTicketAction::class)($ticket, User::query()->findOrFail($ticket->owner_id)))->toBeFalse();
});

// -- Removing ------------------------------------------------------------------

test('removing a ticket keeps the row', function () {
    $ticket = createTicket();

    app(DeleteTicketAction::class)($ticket);

    // "We have no record of that" is the worst answer a support desk can give.
    expect(Ticket::query()->whereKey($ticket->id)->exists())->toBeFalse()
        ->and(Ticket::query()->withTrashed()->whereKey($ticket->id)->exists())->toBeTrue();
});

// -- Age and resolution time ---------------------------------------------------

test('the age of an open ticket runs to now', function () {
    Carbon::setTestNow('2026-09-13 09:00:00');
    $ticket = createTicket();

    Carbon::setTestNow('2026-09-13 15:00:00');

    expect($ticket->fresh()->ageInHours())->toBe(6.0)
        ->and($ticket->fresh()->hoursToResolve())->toBeNull();

    Carbon::setTestNow();
});

test('the age of a resolved ticket stops at the resolution', function () {
    Carbon::setTestNow('2026-09-13 09:00:00');
    $ticket = createTicket();

    Carbon::setTestNow('2026-09-13 12:30:00');
    app(ChangeTicketStatusAction::class)($ticket, TicketStatus::Resolved);

    Carbon::setTestNow('2026-09-20 09:00:00');

    // Otherwise a ticket dealt with in an afternoon reads as having been open
    // for a week.
    expect($ticket->fresh()->ageInHours())->toBe(3.5)
        ->and($ticket->fresh()->hoursToResolve())->toBe(3.5);

    Carbon::setTestNow();
});

// -- Access --------------------------------------------------------------------

test('an agent on their own records only sees their own tickets', function () {
    $agent = ticketUser();
    Ticket::factory()->ownedBy($agent)->create(['subject' => 'Mine']);
    Ticket::factory()->create(['subject' => 'Somebody else']);

    expect(Ticket::query()->visibleTo($agent)->pluck('subject')->all())->toBe(['Mine']);
});

test('the policy asks both questions', function () {
    $agent = ticketUser(['tickets.view', 'tickets.update']);
    $mine = Ticket::factory()->ownedBy($agent)->create();
    $theirs = Ticket::factory()->create();

    expect($agent->can('view', $mine))->toBeTrue()
        // Holds the permission, but the record is outside their access level.
        ->and($agent->can('view', $theirs))->toBeFalse()
        ->and($agent->can('update', $mine))->toBeTrue()
        // Holds neither the assign nor the delete permission.
        ->and($agent->can('assign', $mine))->toBeFalse()
        ->and($agent->can('delete', $mine))->toBeFalse();
});

test('a removed ticket is still reachable by whoever could see it', function () {
    $agent = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($agent)->create();
    app(DeleteTicketAction::class)($ticket);

    // The show page opens a removed ticket rather than 404ing on a reference a
    // customer is reading down the telephone.
    expect($agent->can('view', $ticket))->toBeTrue();
});

// -- Wiring --------------------------------------------------------------------

test('tickets are registered where every module has to be', function () {
    expect(CustomFieldRegistry::subjects())->toHaveKey('tickets')
        ->and(TimelineRegistry::subjects())->toHaveKey('tickets')
        ->and(WorkflowModules::builtIn())->toHaveKey('tickets');
});

test('a change to a ticket is audited with an allowlist, not everything', function () {
    $ticket = createTicket();

    app(ChangeTicketStatusAction::class)($ticket, TicketStatus::Open);

    $entry = Activity::query()
        ->where('log_name', AuditLogger::LOG_NAME)
        ->where('subject_type', $ticket->getMorphClass())
        ->where('subject_id', $ticket->id)
        ->latest('id')
        ->firstOrFail();

    expect(array_keys($entry->properties['attributes'] ?? []))->toContain('status')
        ->and(array_keys($entry->properties['attributes'] ?? []))->not->toContain('description');
});
