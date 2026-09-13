<?php

use App\Domain\Support\Actions\AddTicketCommentAction;
use App\Domain\Support\Actions\DeleteTicketCommentAction;
use App\Domain\Support\Actions\ToggleTicketWatchAction;
use App\Domain\Support\Models\Ticket;
use App\Domain\Support\Models\TicketComment;
use App\Livewire\Support\TicketShow;
use App\Models\User;
use Livewire\Livewire;

// -- Adding --------------------------------------------------------------------

test('a reply is added to the ticket', function () {
    $agent = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($agent)->create();

    $comment = app(AddTicketCommentAction::class)($ticket, 'We have ordered the part.', $agent);

    expect($comment->body)->toBe('We have ordered the part.')
        ->and($comment->author_id)->toBe($agent->id)
        ->and($comment->is_internal)->toBeFalse()
        ->and($comment->from_customer)->toBeFalse()
        ->and($ticket->comments()->count())->toBe(1);
});

test('an empty reply is refused', function (string $body) {
    $agent = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($agent)->create();

    expect(fn () => app(AddTicketCommentAction::class)($ticket, $body, $agent))
        ->toThrow(RuntimeException::class);

    expect(TicketComment::query()->count())->toBe(0);
})->with([
    'empty' => [''],
    'whitespace' => ["   \n  "],
]);

test('a reply is trimmed', function () {
    $agent = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($agent)->create();

    $comment = app(AddTicketCommentAction::class)($ticket, '  We have ordered the part.  ', $agent);

    expect($comment->body)->toBe('We have ordered the part.');
});

test('an internal note is marked internal', function () {
    $agent = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($agent)->create();

    $comment = app(AddTicketCommentAction::class)($ticket, 'Third time this month.', $agent, internal: true);

    expect($comment->is_internal)->toBeTrue();
});

test('a customer reply is never internal, whatever was asked for', function () {
    $ticket = Ticket::factory()->create();

    $comment = app(AddTicketCommentAction::class)(
        ticket: $ticket,
        body: 'It is still doing it.',
        author: null,
        internal: true,
        fromCustomer: true,
        authorName: 'Dana Okafor',
    );

    // "Internal" means the customer cannot see it; hiding their own words from
    // them is meaningless, so the flag is refused rather than stored.
    expect($comment->is_internal)->toBeFalse()
        ->and($comment->from_customer)->toBeTrue()
        ->and($comment->author_id)->toBeNull()
        ->and($comment->authorLabel())->toBe('Dana Okafor');
});

test('the public scope leaves internal notes out', function () {
    $agent = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($agent)->create();

    app(AddTicketCommentAction::class)($ticket, 'Sent to them.', $agent);
    app(AddTicketCommentAction::class)($ticket, 'Between us.', $agent, internal: true);

    expect(TicketComment::query()->public()->pluck('body')->all())->toBe(['Sent to them.']);
});

test('a comment whose author has gone still says who it was not', function () {
    $agent = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($agent)->create();
    $comment = TicketComment::factory()->on($ticket)->create(['author_id' => null]);

    expect($comment->authorLabel())->toBe('Somebody who has since left');
});

test('the conversation is read oldest first', function () {
    $agent = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($agent)->create();

    app(AddTicketCommentAction::class)($ticket, 'First.', $agent);
    app(AddTicketCommentAction::class)($ticket, 'Second.', $agent);
    app(AddTicketCommentAction::class)($ticket, 'Third.', $agent);

    // A thread is read downwards, unlike the timeline, which is newest first.
    expect($ticket->fresh()->comments->pluck('body')->all())->toBe(['First.', 'Second.', 'Third.']);
});

// -- Removing ------------------------------------------------------------------

test('removing a reply keeps the row', function () {
    $agent = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($agent)->create();
    $comment = app(AddTicketCommentAction::class)($ticket, 'Sent in error.', $agent);

    app(DeleteTicketCommentAction::class)($comment);

    expect(TicketComment::query()->whereKey($comment->id)->exists())->toBeFalse()
        ->and(TicketComment::query()->withTrashed()->whereKey($comment->id)->exists())->toBeTrue();
});

// -- Authorization -------------------------------------------------------------

test('replying needs the permission to update the ticket', function () {
    $viewer = ticketUser();
    $ticket = Ticket::factory()->ownedBy($viewer)->create();
    $comment = TicketComment::factory()->on($ticket)->create();

    expect($viewer->can('create', [TicketComment::class, $ticket]))->toBeFalse()
        ->and($viewer->can('view', $comment))->toBeTrue();
});

test('a comment on a ticket outside the access level cannot be read', function () {
    $comment = TicketComment::factory()->create();

    expect(ticketUser()->can('view', $comment))->toBeFalse();
});

test('only the author may edit their own words', function () {
    $author = ticketAdmin();
    $other = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($author)->create();
    $comment = TicketComment::factory()->on($ticket)->by($author)->create();

    // An administrator rewriting what a colleague said to a customer is not a
    // correction.
    expect($author->can('update', $comment))->toBeTrue()
        ->and($other->can('update', $comment))->toBeFalse();
});

test('a customer reply cannot be edited by anybody', function () {
    $agent = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($agent)->create();
    $comment = TicketComment::factory()->on($ticket)->fromCustomer()->create();

    expect($agent->can('update', $comment))->toBeFalse();
});

test('somebody who may remove the ticket may remove a reply on it', function () {
    $author = ticketAdmin();
    $manager = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($author)->create();
    $comment = TicketComment::factory()->on($ticket)->by($author)->create();

    expect($manager->can('delete', $comment))->toBeTrue();
});

// -- Watchers ------------------------------------------------------------------

test('watching and unwatching a ticket', function () {
    $agent = ticketAdmin();
    $watcher = User::factory()->create();
    $ticket = Ticket::factory()->ownedBy($agent)->create();

    expect(app(ToggleTicketWatchAction::class)($ticket, $watcher))->toBeTrue()
        ->and($ticket->fresh()->isWatchedBy($watcher))->toBeTrue();

    expect(app(ToggleTicketWatchAction::class)($ticket, $watcher))->toBeFalse()
        ->and($ticket->fresh()->isWatchedBy($watcher))->toBeFalse();
});

test('watching twice is still watching once', function () {
    $agent = ticketAdmin();
    $watcher = User::factory()->create();
    $ticket = Ticket::factory()->ownedBy($agent)->create();

    $ticket->watchers()->syncWithoutDetaching([$watcher->id]);
    $ticket->watchers()->syncWithoutDetaching([$watcher->id]);

    // The unique index is what stops a double-click doubling every later
    // notification.
    expect($ticket->fresh()->watchers)->toHaveCount(1);
});

// -- The screen ----------------------------------------------------------------

test('the ticket page shows the conversation', function () {
    $agent = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($agent)->create();
    app(AddTicketCommentAction::class)($ticket, 'We have ordered the part.', $agent);

    Livewire::actingAs($agent)
        ->test(TicketShow::class, ['ticket' => $ticket])
        ->assertSee('Conversation')
        ->assertSee('We have ordered the part.');
});

test('the page marks an internal note as one', function () {
    $agent = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($agent)->create();
    app(AddTicketCommentAction::class)($ticket, 'Between us.', $agent, internal: true);

    // The one mistake that matters here is not knowing which a message was.
    Livewire::actingAs($agent)
        ->test(TicketShow::class, ['ticket' => $ticket])
        ->assertSee('Internal')
        ->assertSee('not sent to the customer');
});

test('replying from the page adds a comment and clears the box', function () {
    $agent = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($agent)->create();

    Livewire::actingAs($agent)
        ->test(TicketShow::class, ['ticket' => $ticket])
        ->set('reply', 'We have ordered the part.')
        ->call('comment')
        ->assertHasNoErrors()
        ->assertSet('reply', '');

    expect($ticket->comments()->count())->toBe(1);
});

test('an empty reply is refused by the page', function () {
    $agent = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($agent)->create();

    Livewire::actingAs($agent)
        ->test(TicketShow::class, ['ticket' => $ticket])
        ->set('reply', '')
        ->call('comment')
        ->assertHasErrors(['reply' => 'required']);
});

test('the internal box is what makes the note internal', function () {
    $agent = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($agent)->create();

    Livewire::actingAs($agent)
        ->test(TicketShow::class, ['ticket' => $ticket])
        ->set('replyIsInternal', true)
        ->set('reply', 'Third time this month.')
        ->call('comment');

    expect($ticket->comments()->first()->is_internal)->toBeTrue();
});

test('somebody who may only look cannot reply by calling the method', function () {
    $viewer = ticketUser();
    $ticket = Ticket::factory()->ownedBy($viewer)->create();

    Livewire::actingAs($viewer)
        ->test(TicketShow::class, ['ticket' => $ticket])
        ->set('reply', 'Trying anyway.')
        ->call('comment')
        ->assertForbidden();

    expect(TicketComment::query()->count())->toBe(0);
});

test('the follow button follows and unfollows', function () {
    $agent = ticketAdmin();
    $ticket = Ticket::factory()->create();

    $screen = Livewire::actingAs($agent)->test(TicketShow::class, ['ticket' => $ticket]);

    $screen->call('toggleWatch');
    expect($ticket->fresh()->isWatchedBy($agent))->toBeTrue();

    $screen->call('toggleWatch');
    expect($ticket->fresh()->isWatchedBy($agent))->toBeFalse();
});

test('a reply cannot be removed by guessing an id from another ticket', function () {
    $agent = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($agent)->create();
    $elsewhere = TicketComment::factory()->create();

    Livewire::actingAs($agent)
        ->test(TicketShow::class, ['ticket' => $ticket])
        ->call('deleteComment', $elsewhere->id);

    // Scoped to this ticket before the policy is even asked.
    expect(TicketComment::query()->whereKey($elsewhere->id)->exists())->toBeTrue();
});
