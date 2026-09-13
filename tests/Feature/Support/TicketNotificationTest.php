<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Notifications\Enums\RecipientType;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\NotificationEventRegistry;
use App\Domain\Notifications\NotificationMatrix;
use App\Domain\Notifications\TemplateRenderer;
use App\Domain\Notifications\UserNotificationPreferences;
use App\Domain\Settings\SettingsManager;
use App\Domain\Support\Actions\AddTicketCommentAction;
use App\Domain\Support\Actions\AssignTicketAction;
use App\Domain\Support\Actions\ChangeTicketStatusAction;
use App\Domain\Support\Actions\CreateTicketAction;
use App\Domain\Support\Actions\UpdateTicketAction;
use App\Domain\Support\DTOs\TicketData;
use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\Ticket;
use App\Domain\Support\TicketMergeData;
use App\Domain\Support\TicketRecipients;
use App\Jobs\SendNotification;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * The seven ticket events, so a test can walk them rather than naming one.
 *
 * @return array<int, string>
 */
function ticketEventKeys(): array
{
    return array_values(array_filter(
        NotificationEventRegistry::keys(),
        fn (string $key) => str_starts_with($key, 'ticket.')
    ));
}

/**
 * A ticket with a contact we can actually reach, and an agent who is not the
 * person doing things to it — the engine drops an actor's own notifications, so
 * a fixture where they are the same person proves nothing.
 *
 * @return array{0: Ticket, 1: User, 2: User, 3: Contact}
 */
function ticketWithAudience(): array
{
    $agent = ticketAdmin();
    $actor = ticketAdmin();
    $contact = Contact::factory()->create([
        'email' => 'dana@customer.test',
        'mobile' => '+8801700000000',
    ]);

    $ticket = Ticket::factory()->ownedBy($agent)->create([
        'contact_id' => $contact->id,
        'account_id' => $contact->account_id,
    ]);

    return [$ticket, $agent, $actor, $contact];
}

/**
 * Every channel on, for one event and one recipient type.
 */
function ticketChannelsOn(string $event, RecipientType $type, NotificationChannel ...$channels): void
{
    $matrix = app(NotificationMatrix::class);

    foreach ($channels as $channel) {
        $matrix->set($event, $type, $channel, true);
    }
}

beforeEach(function () {
    Cache::flush();
    app(SettingsManager::class)->flush();
    app(NotificationMatrix::class)->flush();

    // Nothing here is about delivery — that is the engine's own suite. These
    // tests are about who is told what, which is decided at dispatch.
    Queue::fake();
});

// -- The registry --------------------------------------------------------------

test('the seven ticket events are registered', function () {
    expect(ticketEventKeys())->toEqualCanonicalizing([
        'ticket.created',
        'ticket.status_changed',
        'ticket.priority_changed',
        'ticket.assigned',
        'ticket.comment_added',
        'ticket.resolved',
        'ticket.closed',
    ]);
});

test('every ticket event only names merge fields its data supplies', function (string $key) {
    $event = NotificationEventRegistry::find($key);

    $supplied = [
        ...TicketMergeData::mergeFields(),
        ...TicketMergeData::moveFields(),
        ...TicketMergeData::priorityFields(),
        ...TicketMergeData::commentFields(),
    ];

    // A template naming a field the event does not declare is refused at save
    // time; an event declaring a field nothing supplies renders as a gap in a
    // message somebody reads.
    foreach (array_keys($event->mergeFields) as $field) {
        expect($supplied)->toHaveKey($field);
    }
})->with(fn () => ticketEventKeys());

test('a ticket template renders every field it names', function (string $key) {
    $event = NotificationEventRegistry::find($key);
    [$ticket] = ticketWithAudience();

    $data = [
        ...TicketMergeData::forMove($ticket, TicketStatus::New, TicketStatus::Open),
        ...TicketMergeData::forPriority($ticket, TicketPriority::Low, TicketPriority::Urgent),
    ];
    $data['ticket'] = [
        ...TicketMergeData::forMove($ticket, TicketStatus::New, TicketStatus::Open)['ticket'],
        ...TicketMergeData::forPriority($ticket, TicketPriority::Low, TicketPriority::Urgent)['ticket'],
    ];
    $data['comment'] = ['author' => 'Dana', 'excerpt' => 'Still broken'];

    $rendered = app(TemplateRenderer::class)->render(
        $event->defaultBody(NotificationChannel::Email).' '.$event->defaultSubject,
        [...$data, 'app' => ['name' => 'CRM', 'url' => 'https://crm.test'], 'recipient' => ['name' => 'Dana']],
    );

    expect($rendered)->not->toContain('{{');
})->with(fn () => ticketEventKeys());

// -- Creating ------------------------------------------------------------------

test('raising a ticket tells the customer, the agent and the administrators', function () {
    $agent = ticketAdmin();
    $actor = ticketAdmin();
    // A third: the actor is excluded as the person doing it, and the agent is
    // already in the list as the assigned agent, so neither of them can prove
    // the administrator row.
    ticketAdmin();
    $contact = Contact::factory()->create(['email' => 'dana@customer.test']);

    app(CreateTicketAction::class)(
        TicketData::fromArray([
            'subject' => 'Printer will not connect',
            'owner_id' => (string) $agent->id,
            'contact_id' => (string) $contact->id,
            'account_id' => (string) $contact->account_id,
        ]),
        $actor,
    );

    $logs = NotificationLog::query()->where('event', 'ticket.created')->get();

    expect($logs->pluck('recipient_type')->unique()->sort()->values()->all())
        ->toContain(RecipientType::Customer->value)
        ->toContain(RecipientType::AssignedAgent->value)
        ->toContain(RecipientType::Admin->value);
});

test('every enabled channel gets its own delivery', function () {
    [$ticket, , $actor] = ticketWithAudience();

    ticketChannelsOn('ticket.status_changed', RecipientType::AssignedAgent,
        NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Sms);

    app(ChangeTicketStatusAction::class)($ticket, TicketStatus::Open, $actor);

    $channels = NotificationLog::query()
        ->where('event', 'ticket.status_changed')
        ->where('recipient_type', RecipientType::AssignedAgent->value)
        ->pluck('channel')
        ->all();

    expect($channels)->toContain('in_app')
        ->toContain('email')
        ->toContain('sms');
});

test('the actor is never told about their own change', function () {
    $agent = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($agent)->create();

    app(ChangeTicketStatusAction::class)($ticket, TicketStatus::Open, $agent);

    expect(NotificationLog::query()->where('user_id', $agent->id)->count())->toBe(0);
});

test('every send is queued, never inline', function () {
    [$ticket, , $actor] = ticketWithAudience();

    app(ChangeTicketStatusAction::class)($ticket, TicketStatus::Open, $actor);

    Queue::assertPushed(SendNotification::class);

    $logs = NotificationLog::query()->where('event', 'ticket.status_changed')->get();

    // Queued or skipped — never Sent. A row already sent while the queue is
    // faked would mean something delivered inline.
    expect($logs->pluck('status')->unique()->all())
        ->not->toContain(NotificationStatus::Sent->value);

    Queue::assertPushed(
        SendNotification::class,
        $logs->where('status', NotificationStatus::Queued->value)->count()
    );
});

// -- Status --------------------------------------------------------------------

test('a status change carries both ends of the move', function () {
    [$ticket, , $actor] = ticketWithAudience();
    app(ChangeTicketStatusAction::class)($ticket, TicketStatus::Pending, $actor);

    $data = TicketMergeData::forMove($ticket->fresh(), TicketStatus::Pending, TicketStatus::Open);

    // "Your ticket is now open" says much less than "moved from waiting on you
    // to open".
    expect($data['ticket']['old_status'])->toBe('Waiting on customer')
        ->and($data['ticket']['new_status'])->toBe('Open');
});

test('resolving and closing fire their own events, not the generic one', function (TicketStatus $status, string $event) {
    [$ticket, , $actor] = ticketWithAudience();

    app(ChangeTicketStatusAction::class)($ticket, $status, $actor);

    // One move must never send a customer two messages.
    expect(NotificationLog::query()->where('event', $event)->count())->toBeGreaterThan(0)
        ->and(NotificationLog::query()->where('event', 'ticket.status_changed')->count())->toBe(0);
})->with(fn () => [
    'resolved' => [TicketStatus::Resolved, 'ticket.resolved'],
    'closed' => [TicketStatus::Closed, 'ticket.closed'],
]);

test('a move to the status it is already in tells nobody', function () {
    [$ticket, , $actor] = ticketWithAudience();

    app(ChangeTicketStatusAction::class)($ticket, TicketStatus::New, $actor);

    expect(NotificationLog::query()->count())->toBe(0);
});

// -- Priority ------------------------------------------------------------------

test('retriage is told to our side and not to the customer', function () {
    [$ticket, , $actor] = ticketWithAudience();

    app(UpdateTicketAction::class)($ticket, TicketData::fromArray([
        'subject' => $ticket->subject,
        'owner_id' => (string) $ticket->owner_id,
        'contact_id' => (string) $ticket->contact_id,
        'account_id' => (string) $ticket->account_id,
        'priority' => (string) TicketPriority::Urgent->value,
    ]), $actor);

    $logs = NotificationLog::query()->where('event', 'ticket.priority_changed')->get();

    expect($logs)->not->toBeEmpty()
        ->and($logs->pluck('recipient_type')->all())->not->toContain(RecipientType::Customer->value);
});

test('the priority event has no customer row for an administrator to switch on', function () {
    // Left off the event rather than filtered later, so the matrix cannot be
    // configured into sending it.
    expect(NotificationEventRegistry::find('ticket.priority_changed')->allows(RecipientType::Customer))->toBeFalse()
        ->and(app(NotificationMatrix::class)->set(
            'ticket.priority_changed', RecipientType::Customer, NotificationChannel::Email, true
        ))->toBeFalse();
});

test('an edit that changes nothing about the priority tells nobody', function () {
    [$ticket, , $actor] = ticketWithAudience();

    app(UpdateTicketAction::class)($ticket, TicketData::fromArray([
        'subject' => 'A corrected subject',
        'owner_id' => (string) $ticket->owner_id,
        'priority' => (string) $ticket->priority()->value,
    ]), $actor);

    // A desk that sent a message for every typo correction would train
    // everybody to ignore all of them.
    expect(NotificationLog::query()->count())->toBe(0);
});

// -- Assignment ----------------------------------------------------------------

test('handing a ticket over tells the new agent', function () {
    [$ticket, , $actor] = ticketWithAudience();
    $newAgent = ticketAdmin();

    app(AssignTicketAction::class)($ticket, $newAgent, $actor);

    expect(NotificationLog::query()
        ->where('event', 'ticket.assigned')
        ->where('user_id', $newAgent->id)
        ->where('recipient_type', RecipientType::AssignedAgent->value)
        ->exists())->toBeTrue();
});

test('the assignment message names the new agent, not the old one', function () {
    [$ticket, $agent, $actor] = ticketWithAudience();
    $newAgent = ticketAdmin();

    app(AssignTicketAction::class)($ticket, $newAgent, $actor);

    // The owner relation is replaced rather than left stale, or the message
    // telling somebody they have it would name the person who used to.
    expect(TicketMergeData::for($ticket)['ticket']['agent'])->toBe($newAgent->name)
        ->and($agent->name)->not->toBe($newAgent->name);
});

test('assignment is not sent to the customer', function () {
    [$ticket, , $actor] = ticketWithAudience();

    app(AssignTicketAction::class)($ticket, ticketAdmin(), $actor);

    expect(NotificationLog::query()->where('recipient_type', RecipientType::Customer->value)->count())->toBe(0);
});

// -- Comments ------------------------------------------------------------------

test('a reply tells the customer', function () {
    [$ticket, $agent] = ticketWithAudience();

    app(AddTicketCommentAction::class)($ticket, 'We have ordered the part.', $agent);

    expect(NotificationLog::query()
        ->where('event', 'ticket.comment_added')
        ->where('recipient_type', RecipientType::Customer->value)
        ->where('recipient', 'dana@customer.test')
        ->exists())->toBeTrue();
});

test('an internal note never reaches the customer', function () {
    [$ticket, $agent] = ticketWithAudience();

    app(AddTicketCommentAction::class)($ticket, 'Third time this month.', $agent, internal: true);

    // The recipient list is built without the customer rather than filtered
    // afterwards, so there is no ordering of conditions that leaks it.
    expect(NotificationLog::query()
        ->where('recipient_type', RecipientType::Customer->value)
        ->count())->toBe(0)
        ->and(NotificationLog::query()->where('event', 'ticket.comment_added')->count())
        ->toBeGreaterThan(0);
});

// -- Watchers ------------------------------------------------------------------

test('a watcher hears about a ticket that is not theirs', function () {
    [$ticket, , $actor] = ticketWithAudience();
    $watcher = ticketAdmin();
    $ticket->watchers()->syncWithoutDetaching([$watcher->id]);

    app(ChangeTicketStatusAction::class)($ticket->fresh(), TicketStatus::Open, $actor);

    expect(NotificationLog::query()
        ->where('event', 'ticket.status_changed')
        ->where('user_id', $watcher->id)
        ->exists())->toBeTrue();
});

test('somebody who is both a watcher and the agent hears once', function () {
    [$ticket, $agent, $actor] = ticketWithAudience();
    $ticket->watchers()->syncWithoutDetaching([$agent->id]);

    app(ChangeTicketStatusAction::class)($ticket->fresh(), TicketStatus::Open, $actor);

    expect(NotificationLog::query()
        ->where('event', 'ticket.status_changed')
        ->where('user_id', $agent->id)
        ->where('channel', NotificationChannel::InApp->value)
        ->count())->toBe(1);
});

// -- The matrix and personal preferences ---------------------------------------

test('turning a channel off in the matrix suppresses only that channel', function () {
    [$ticket, $agent, $actor] = ticketWithAudience();

    ticketChannelsOn('ticket.status_changed', RecipientType::AssignedAgent,
        NotificationChannel::InApp, NotificationChannel::Email);

    app(NotificationMatrix::class)->set(
        'ticket.status_changed', RecipientType::AssignedAgent, NotificationChannel::Email, false
    );

    app(ChangeTicketStatusAction::class)($ticket, TicketStatus::Open, $actor);

    $channels = NotificationLog::query()
        ->where('event', 'ticket.status_changed')
        ->where('user_id', $agent->id)
        ->pluck('channel')
        ->all();

    expect($channels)->toContain('in_app')->not->toContain('email');
});

test('a personal mute overrides the matrix default', function () {
    [$ticket, $agent, $actor] = ticketWithAudience();

    app(UserNotificationPreferences::class)->setEvent(
        $agent, 'ticket.status_changed', NotificationChannel::InApp, false
    );

    app(ChangeTicketStatusAction::class)($ticket, TicketStatus::Open, $actor);

    expect(NotificationLog::query()
        ->where('event', 'ticket.status_changed')
        ->where('user_id', $agent->id)
        ->where('channel', NotificationChannel::InApp->value)
        ->count())->toBe(0);
});

// -- Addressing ----------------------------------------------------------------

test('the customer is reached at their email and their mobile, not one for both', function () {
    [$ticket, $agent, , $contact] = ticketWithAudience();

    ticketChannelsOn('ticket.comment_added', RecipientType::Customer,
        NotificationChannel::Email, NotificationChannel::Sms);

    app(AddTicketCommentAction::class)($ticket, 'We have ordered the part.', $agent);

    $logs = NotificationLog::query()
        ->where('recipient_type', RecipientType::Customer->value)
        ->pluck('recipient', 'channel')
        ->all();

    // Posting an email address to an SMS provider is a delivery failure at
    // best; a contact holds the two in different columns.
    expect($logs['email'])->toBe($contact->email)
        ->and($logs['sms'])->toBe($contact->mobile);
});

test('a ticket with nobody attached tells our side and nobody else', function () {
    $agent = ticketAdmin();
    $actor = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($agent)->create();

    app(ChangeTicketStatusAction::class)($ticket, TicketStatus::Open, $actor);

    expect(NotificationLog::query()->where('recipient_type', RecipientType::Customer->value)->count())->toBe(0)
        ->and(NotificationLog::query()->where('user_id', $agent->id)->count())->toBeGreaterThan(0);
});

test('a customer is never queued an in-app notification', function () {
    [$ticket, $agent] = ticketWithAudience();

    app(AddTicketCommentAction::class)($ticket, 'We have ordered the part.', $agent);

    // An in-app notification has nowhere to live without an account, and a
    // contact is not a user. Skipped and logged, not attempted.
    $inApp = NotificationLog::query()
        ->where('recipient_type', RecipientType::Customer->value)
        ->where('channel', NotificationChannel::InApp->value)
        ->get();

    expect($inApp->every(fn (NotificationLog $log) => $log->status() === NotificationStatus::Skipped))->toBeTrue()
        ->and($inApp->pluck('error')->filter()->isNotEmpty())->toBeTrue();
});

test('a contact we hold no address for is not a recipient', function () {
    $agent = ticketAdmin();
    $contact = Contact::factory()->create(['email' => null, 'phone' => null, 'mobile' => null]);
    $ticket = Ticket::factory()->ownedBy($agent)->create([
        'contact_id' => $contact->id,
        'account_id' => $contact->account_id,
    ]);

    expect(app(TicketRecipients::class)->customer($ticket->fresh()))->toBe([]);
});

test('an empty address column is not an address', function () {
    $agent = ticketAdmin();
    $contact = Contact::factory()->create(['email' => '   ', 'phone' => null, 'mobile' => null]);
    $ticket = Ticket::factory()->ownedBy($agent)->create([
        'contact_id' => $contact->id,
        'account_id' => $contact->account_id,
    ]);

    // `??` alone treats an empty string as a usable address, and the mail
    // driver would then fail on every one of them.
    expect(app(TicketRecipients::class)->customer($ticket->fresh()))->toBe([]);
});
