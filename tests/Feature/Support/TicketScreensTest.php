<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Shared\Enums\ExportFormat;
use App\Domain\Shared\Enums\FilterOperator;
use App\Domain\Shared\Enums\ViewMode;
use App\Domain\Shared\Exports\ExportRequest;
use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketSource;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\Ticket;
use App\Domain\Support\TicketExportSource;
use App\Domain\Support\TicketFields;
use App\Livewire\Support\TicketForm;
use App\Livewire\Support\TicketShow;
use App\Livewire\Support\TicketsIndex;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow('2026-09-13 09:00:00');
});

// -- The queue -----------------------------------------------------------------

test('the queue renders what the viewer can see', function () {
    $user = ticketAdmin();
    Ticket::factory()->ownedBy($user)->create(['subject' => 'Printer will not connect']);

    Livewire::actingAs($user)
        ->test(TicketsIndex::class)
        ->assertOk()
        ->assertSee('Printer will not connect');
});

test('the queue is refused without the view permission', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(TicketsIndex::class)
        ->assertForbidden();
});

test('a ticket outside the access level never reaches the page', function () {
    $agent = ticketUser();
    Ticket::factory()->ownedBy($agent)->create(['subject' => 'Mine']);
    Ticket::factory()->create(['subject' => 'Somebody else']);

    Livewire::actingAs($agent)
        ->test(TicketsIndex::class)
        ->assertSee('Mine')
        ->assertDontSee('Somebody else');
});

test('the queue is most urgent first, then longest waiting', function () {
    $user = ticketAdmin();

    Carbon::setTestNow('2026-09-10 09:00:00');
    Ticket::factory()->ownedBy($user)->withPriority(TicketPriority::Normal)->create(['subject' => 'Old and ordinary']);

    Carbon::setTestNow('2026-09-12 09:00:00');
    Ticket::factory()->ownedBy($user)->withPriority(TicketPriority::Urgent)->create(['subject' => 'New and urgent']);
    Carbon::setTestNow('2026-09-11 09:00:00');
    Ticket::factory()->ownedBy($user)->withPriority(TicketPriority::Urgent)->create(['subject' => 'Older and urgent']);

    $rows = Livewire::actingAs($user)->test(TicketsIndex::class)->instance()->rows();

    // That is the order an agent works a queue in.
    expect($rows->pluck('subject')->all())->toBe(['Older and urgent', 'New and urgent', 'Old and ordinary']);
});

test('priority sorts by urgency, not alphabetically', function () {
    $user = ticketAdmin();
    Ticket::factory()->ownedBy($user)->withPriority(TicketPriority::Low)->create(['subject' => 'Low one']);
    Ticket::factory()->ownedBy($user)->withPriority(TicketPriority::Urgent)->create(['subject' => 'Urgent one']);
    Ticket::factory()->ownedBy($user)->withPriority(TicketPriority::High)->create(['subject' => 'High one']);

    // A string column would come back "high, low, normal, urgent", which is
    // visibly wrong. This is why the enum is backed by a rank.
    $rows = Livewire::actingAs($user)
        ->test(TicketsIndex::class)
        ->call('sort', 'priority')
        ->call('sort', 'priority')
        ->instance()
        ->rows();

    expect($rows->pluck('subject')->all())->toBe(['Urgent one', 'High one', 'Low one']);
});

// -- The four views ------------------------------------------------------------

test('every view mode renders with data', function (ViewMode $mode) {
    $user = ticketAdmin();
    Ticket::factory()->ownedBy($user)->create(['subject' => 'Printer will not connect']);

    Livewire::actingAs($user)
        ->test(TicketsIndex::class)
        ->call('setViewMode', $mode->value)
        ->assertOk()
        ->assertSee('Printer will not connect');
})->with(ViewMode::cases());

test('every view mode renders when there is nothing', function (ViewMode $mode) {
    Livewire::actingAs(ticketAdmin())
        ->test(TicketsIndex::class)
        ->call('setViewMode', $mode->value)
        ->assertOk()
        ->assertSee('Nothing is waiting');
})->with(ViewMode::cases());

// -- The board -----------------------------------------------------------------

test('the board groups by status, in the order a ticket travels', function () {
    $screen = Livewire::actingAs(ticketAdmin())->test(TicketsIndex::class)->instance();

    expect($screen->dataViewKanbanField())->toBe('status')
        ->and(array_column($screen->dataViewKanbanColumns(), 'value'))
        ->toBe(['new', 'open', 'pending', 'on_hold', 'resolved', 'closed']);
});

test('a drag into another column moves the ticket and stamps it', function () {
    $user = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(TicketsIndex::class)
        ->call('setViewMode', ViewMode::Kanban->value)
        ->call('moveCard', $ticket->id, TicketStatus::Resolved->value)
        ->assertReturned(true);

    // Through the action, not by writing the column: it is the action that
    // owns resolved_at.
    expect($ticket->fresh()->status())->toBe(TicketStatus::Resolved)
        ->and($ticket->fresh()->resolved_at)->not->toBeNull();
});

test('a drag back into an open column clears the stamps', function () {
    $user = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($user)->closed()->create();

    Livewire::actingAs($user)
        ->test(TicketsIndex::class)
        ->call('moveCard', $ticket->id, TicketStatus::Open->value)
        ->assertReturned(true);

    expect($ticket->fresh()->resolved_at)->toBeNull()
        ->and($ticket->fresh()->closed_at)->toBeNull();
});

test('a drop back into the column it came from reports no move', function () {
    $user = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(TicketsIndex::class)
        ->call('moveCard', $ticket->id, TicketStatus::New->value)
        ->assertReturned(false);
});

test('a column value the board does not offer is refused', function () {
    $user = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(TicketsIndex::class)
        ->call('moveCard', $ticket->id, 'archived')
        ->assertReturned(false);

    expect($ticket->fresh()->status())->toBe(TicketStatus::New);
});

test('a card outside the access level cannot be dragged by guessing its id', function () {
    $theirs = Ticket::factory()->create();
    $agent = ticketUser(['tickets.view', 'tickets.update']);

    Livewire::actingAs($agent)
        ->test(TicketsIndex::class)
        ->call('moveCard', $theirs->id, TicketStatus::Resolved->value)
        ->assertReturned(false);

    expect($theirs->fresh()->status())->toBe(TicketStatus::New);
});

// -- Quick filters and totals --------------------------------------------------

test('the quick filters narrow the queue', function (string $chip, string $expected) {
    $user = ticketAdmin();
    $account = Account::factory()->create();

    Ticket::factory()->ownedBy($user)->forAccount($account)->create(['subject' => 'Attached and open']);
    Ticket::factory()->ownedBy($user)->create(['subject' => 'Nobody attached']);
    Ticket::factory()->ownedBy($user)->forAccount($account)->withPriority(TicketPriority::Urgent)
        ->create(['subject' => 'Burning']);
    Ticket::factory()->ownedBy($user)->forAccount($account)->resolved()->create(['subject' => 'Dealt with']);

    $rows = Livewire::actingAs($user)
        ->test(TicketsIndex::class)
        ->call('setQuickFilter', $chip)
        ->instance()
        ->rows();

    expect($rows->pluck('subject')->all())->toContain($expected);
})->with([
    'open' => ['open', 'Attached and open'],
    'unlinked' => ['unlinked', 'Nobody attached'],
    'urgent' => ['urgent', 'Burning'],
    'resolved' => ['resolved', 'Dealt with'],
]);

test('the mine chip excludes what belongs to somebody else', function () {
    $user = ticketAdmin();
    Ticket::factory()->ownedBy($user)->create(['subject' => 'Mine']);
    Ticket::factory()->create(['subject' => 'Theirs']);

    $rows = Livewire::actingAs($user)
        ->test(TicketsIndex::class)
        ->call('setQuickFilter', 'mine')
        ->instance()
        ->rows();

    expect($rows->pluck('subject')->all())->toBe(['Mine']);
});

test('the unlinked chip means no customer attached, not no agent', function () {
    $user = ticketAdmin();
    $account = Account::factory()->create();
    Ticket::factory()->ownedBy($user)->forAccount($account)->create(['subject' => 'Attached']);
    Ticket::factory()->ownedBy($user)->create(['subject' => 'Floating']);

    // Every ticket has an agent, because the column is NOT NULL. What goes
    // missing is one nobody attached to a customer.
    $rows = Livewire::actingAs($user)
        ->test(TicketsIndex::class)
        ->call('setQuickFilter', 'unlinked')
        ->instance()
        ->rows();

    expect($rows->pluck('subject')->all())->toBe(['Floating']);
});

test('a chip the screen does not offer is ignored', function () {
    Livewire::actingAs(ticketAdmin())
        ->test(TicketsIndex::class)
        ->call('setQuickFilter', 'everything')
        ->assertSet('quickFilter', '');
});

test('pressing the same chip again clears it', function () {
    Livewire::actingAs(ticketAdmin())
        ->test(TicketsIndex::class)
        ->call('setQuickFilter', 'urgent')
        ->assertSet('quickFilter', 'urgent')
        ->call('setQuickFilter', 'urgent')
        ->assertSet('quickFilter', '');
});

test('the totals describe the whole filtered set, not the page', function () {
    $user = ticketAdmin();
    Ticket::factory()->ownedBy($user)->count(3)->create();
    Ticket::factory()->ownedBy($user)->withPriority(TicketPriority::Urgent)->count(2)->create();
    Ticket::factory()->ownedBy($user)->resolved()->create();

    $totals = Livewire::actingAs($user)
        ->test(TicketsIndex::class)
        ->set('perPage', 25)
        ->instance()
        ->totals();

    expect($totals['count'])->toBe(6)
        ->and($totals['open'])->toBe(5)
        ->and($totals['urgent'])->toBe(2)
        ->and($totals['resolved'])->toBe(1);
});

test('the totals leave out removed tickets', function () {
    $user = ticketAdmin();
    Ticket::factory()->ownedBy($user)->count(2)->create();
    Ticket::factory()->ownedBy($user)->create()->delete();

    // applyScopes() before getQuery(), or the soft-delete scope is dropped and
    // removed rows come back into the counts.
    $totals = Livewire::actingAs($user)->test(TicketsIndex::class)->instance()->totals();

    expect($totals['count'])->toBe(2);
});

// -- Row actions ---------------------------------------------------------------

test('removing from the queue keeps the row', function () {
    $user = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(TicketsIndex::class)
        ->call('delete', $ticket->id);

    expect(Ticket::query()->whereKey($ticket->id)->exists())->toBeFalse()
        ->and(Ticket::query()->withTrashed()->whereKey($ticket->id)->exists())->toBeTrue();
});

test('removing a selection only touches what the person may remove', function () {
    $user = ticketAdmin();
    $mine = Ticket::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(TicketsIndex::class)
        ->call('toggleSelection', $mine->id)
        ->call('deleteSelected');

    expect(Ticket::query()->whereKey($mine->id)->exists())->toBeFalse();
});

test('somebody who may only look cannot remove by calling the method', function () {
    $agent = ticketUser();
    $ticket = Ticket::factory()->ownedBy($agent)->create();

    Livewire::actingAs($agent)
        ->test(TicketsIndex::class)
        ->call('delete', $ticket->id)
        ->assertForbidden();

    expect(Ticket::query()->whereKey($ticket->id)->exists())->toBeTrue();
});

// -- Searching and filtering ---------------------------------------------------

test('search covers the reference, the subject and the description', function (string $term, string $expected) {
    $user = ticketAdmin();
    Ticket::factory()->ownedBy($user)->create(['subject' => 'Printer will not connect', 'description' => 'Since the update']);
    Ticket::factory()->ownedBy($user)->create(['subject' => 'Password reset', 'description' => 'Locked out of the portal']);

    $rows = Livewire::actingAs($user)
        ->test(TicketsIndex::class)
        ->set('search', $term)
        ->instance()
        ->rows();

    expect($rows->pluck('subject')->all())->toBe([$expected]);
})->with([
    'subject' => ['Printer', 'Printer will not connect'],
    'description' => ['Locked out', 'Password reset'],
]);

test('a reference finds its ticket', function () {
    $user = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($user)->create(['subject' => 'Printer will not connect']);
    Ticket::factory()->ownedBy($user)->create(['subject' => 'Password reset']);

    // What a customer reads down the telephone has to find the ticket.
    $rows = Livewire::actingAs($user)
        ->test(TicketsIndex::class)
        ->set('search', $ticket->fresh()->number)
        ->instance()
        ->rows();

    expect($rows->pluck('subject')->all())->toBe(['Printer will not connect']);
});

test('the model scope and the queue search return the same rows', function () {
    $user = ticketAdmin();
    Ticket::factory()->ownedBy($user)->count(3)->create(['subject' => 'Printer will not connect']);
    Ticket::factory()->ownedBy($user)->create(['subject' => 'Password reset']);

    $listed = Livewire::actingAs($user)
        ->test(TicketsIndex::class)
        ->set('search', 'Printer')
        ->instance()
        ->rows()
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    $scoped = Ticket::query()->visibleTo($user)->search('Printer')->pluck('id')->sort()->values()->all();

    // A queued export rebuilds its query from the model scope, so the two
    // disagreeing would mean an export containing rows the queue never showed.
    expect($listed)->toBe($scoped);
});

test('the filter builder filters on a declared field', function () {
    $user = ticketAdmin();
    Ticket::factory()->ownedBy($user)->from(TicketSource::Email)->create(['subject' => 'Came by email']);
    Ticket::factory()->ownedBy($user)->create(['subject' => 'Typed in']);

    $rows = Livewire::actingAs($user)
        ->test(TicketsIndex::class)
        ->set('filters.conditions', [[
            'field' => 'source',
            'operator' => FilterOperator::Equals->value,
            'value' => TicketSource::Email->value,
            'value2' => null,
        ]])
        ->instance()
        ->rows();

    expect($rows->pluck('subject')->all())->toBe(['Came by email']);
});

test('a field the screen does not declare never reaches the query', function () {
    $user = ticketAdmin();
    Ticket::factory()->ownedBy($user)->count(2)->create();

    $rows = Livewire::actingAs($user)
        ->test(TicketsIndex::class)
        ->set('filters.conditions', [[
            'field' => 'description',
            'operator' => FilterOperator::IsNotEmpty->value,
            'value' => null,
            'value2' => null,
        ]])
        ->instance()
        ->rows();

    // Dropped rather than applied: the condition is checked against
    // dataViewFilterFields() before it reaches SQL.
    expect($rows->total())->toBe(2);
});

test('every filter field is a real column, so an index can cover it', function () {
    $columns = Schema::getColumnListing('tickets');

    foreach (TicketFields::filters() as $field) {
        expect($columns)->toContain($field->column());
    }
});

test('age is not sortable, because the screen and SQL would disagree', function () {
    // It is measured to the resolution or to now, which the query does not
    // carry — so it is deliberately not a sort key.
    expect(TicketFields::sortColumn('age'))->toBeNull()
        ->and(TicketFields::sortColumn('priority'))->toBe('tickets.priority');
});

// -- Export --------------------------------------------------------------------

test('the export is offered only to somebody who may export', function () {
    expect(Livewire::actingAs(ticketAdmin())->test(TicketsIndex::class)->instance()->dataViewExportSource())
        ->not->toBeNull()
        ->and(Livewire::actingAs(ticketUser())->test(TicketsIndex::class)->instance()->dataViewExportSource())
        ->toBeNull();
});

test('a queued export re-applies the access scope', function () {
    $agent = ticketUser(['tickets.view', 'tickets.export']);
    $mine = Ticket::factory()->ownedBy($agent)->create();
    Ticket::factory()->create();

    $request = new ExportRequest(
        source: TicketExportSource::class,
        format: ExportFormat::Csv,
        module: 'tickets',
        columns: ['subject' => 'Ticket'],
        userId: $agent->id,
    );

    // A queued export runs with no session, so forgetting visibleTo() here
    // would send somebody rows they could not see.
    expect(app(TicketExportSource::class)->exportQuery($request)->pluck('id')->all())->toBe([$mine->id]);
});

test('the export writes labels, not stored ranks', function () {
    $user = ticketAdmin();
    $account = Account::factory()->create(['name' => 'Acme']);
    $ticket = Ticket::factory()->ownedBy($user)->forAccount($account)
        ->withPriority(TicketPriority::Urgent)
        ->from(TicketSource::Email)
        ->create(['subject' => 'Printer will not connect']);

    $request = new ExportRequest(
        source: TicketExportSource::class,
        format: ExportFormat::Csv,
        module: 'tickets',
        columns: [
            'subject' => 'Ticket',
            'status' => 'Status',
            'priority' => 'Priority',
            'source' => 'Came in by',
            'account' => 'Account',
        ],
        userId: $user->id,
    );

    // A spreadsheet column of 4s means nothing to whoever opens it.
    expect(app(TicketExportSource::class)->exportRow($ticket, $request))->toBe([
        'Printer will not connect',
        'New',
        'Urgent',
        'Email',
        'Acme',
    ]);
});

// -- The form ------------------------------------------------------------------

test('the form raises a ticket', function () {
    $user = ticketAdmin();

    Livewire::actingAs($user)
        ->test(TicketForm::class)
        ->set('subject', 'Printer will not connect')
        ->set('description', 'It worked yesterday.')
        ->set('priority', (string) TicketPriority::High->value)
        ->call('save')
        ->assertHasNoErrors();

    $ticket = Ticket::query()->firstOrFail();

    expect($ticket->subject)->toBe('Printer will not connect')
        ->and($ticket->priority())->toBe(TicketPriority::High)
        ->and($ticket->status())->toBe(TicketStatus::New)
        ->and($ticket->owner_id)->toBe($user->id);
});

test('the form edits a ticket', function () {
    $user = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($user)->create(['subject' => 'Printer will not connect']);

    Livewire::actingAs($user)
        ->test(TicketForm::class, ['ticket' => $ticket])
        ->assertSet('subject', 'Printer will not connect')
        ->set('subject', 'Printer prints blank pages')
        ->call('save')
        ->assertHasNoErrors();

    expect($ticket->fresh()->subject)->toBe('Printer prints blank pages');
});

test('the form has no status control', function () {
    // ChangeTicketStatusAction owns status. A field here would be a second
    // path to "resolved", and the two would disagree about resolved_at.
    $user = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(TicketForm::class, ['ticket' => $ticket])
        ->assertDontSeeHtml('wire:model="status"')
        ->assertDontSeeHtml('wire:model.live="status"');
});

test('the form insists on a subject', function () {
    Livewire::actingAs(ticketAdmin())
        ->test(TicketForm::class)
        ->set('subject', '')
        ->call('save')
        ->assertHasErrors(['subject' => 'required']);
});

test('the form refuses a priority the enum does not offer', function () {
    Livewire::actingAs(ticketAdmin())
        ->test(TicketForm::class)
        ->set('subject', 'Printer will not connect')
        ->set('priority', '9')
        ->call('save')
        ->assertHasErrors(['priority']);
});

test('the form is refused without the create permission', function () {
    Livewire::actingAs(ticketUser())
        ->test(TicketForm::class)
        ->assertForbidden();
});

test('an account the person cannot reach is refused by the form', function () {
    $stranger = Account::factory()->create();
    $agent = ticketUser(['tickets.view', 'tickets.create', 'accounts.view']);

    // `exists` proves the account is real, never that this person may reach it.
    Livewire::actingAs($agent)
        ->test(TicketForm::class)
        ->set('subject', 'Printer will not connect')
        ->set('account_id', (string) $stranger->id)
        ->call('save')
        ->assertHasErrors(['account_id']);
});

test('choosing another account clears the contact already chosen', function () {
    $account = Account::factory()->create();
    $other = Account::factory()->create();
    $contact = Contact::factory()->create(['account_id' => $account->id]);

    Livewire::actingAs(ticketAdmin())
        ->test(TicketForm::class)
        ->set('account_id', (string) $account->id)
        ->set('contact_id', (string) $contact->id)
        ->set('account_id', (string) $other->id)
        ->assertSet('contact_id', null);
});

test('the contact picker is not narrowed until an account is chosen', function () {
    $account = Account::factory()->create();
    Contact::factory()->create(['account_id' => $account->id, 'first_name' => 'Dana', 'last_name' => 'Okafor']);

    // Plenty of tickets come from somebody whose organisation nobody has
    // recorded yet; refusing to offer them would mean typing the account first.
    $screen = Livewire::actingAs(ticketAdmin())->test(TicketForm::class);

    expect(array_column($screen->instance()->searchContacts('Dana')['options'], 'label'))
        ->toBe(['Dana Okafor']);
});

test('somebody who may not assign cannot hand the ticket to another agent', function () {
    $agent = ticketUser(['tickets.view', 'tickets.create', 'tickets.update']);
    $ticket = Ticket::factory()->ownedBy($agent)->create();
    $other = User::factory()->create();

    Livewire::actingAs($agent)
        ->test(TicketForm::class, ['ticket' => $ticket])
        ->set('owner_id', (string) $other->id)
        ->call('save')
        ->assertHasNoErrors();

    // Ignored server-side, not merely hidden.
    expect($ticket->fresh()->owner_id)->toBe($agent->id);
});

// -- The ticket page -----------------------------------------------------------

test('the ticket page shows the ticket', function () {
    $user = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($user)->create([
        'subject' => 'Printer will not connect',
        'description' => 'It worked yesterday.',
    ]);

    Livewire::actingAs($user)
        ->test(TicketShow::class, ['ticket' => $ticket])
        ->assertOk()
        ->assertSee('Printer will not connect')
        ->assertSee('It worked yesterday.')
        ->assertSee($ticket->fresh()->number);
});

test('the ticket page is refused for a ticket outside the access level', function () {
    $theirs = Ticket::factory()->create();

    Livewire::actingAs(ticketUser())
        ->test(TicketShow::class, ['ticket' => $theirs])
        ->assertForbidden();
});

test('moving it on from the ticket page goes through the action', function () {
    $user = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(TicketShow::class, ['ticket' => $ticket])
        ->call('moveTo', TicketStatus::Resolved->value);

    expect($ticket->fresh()->status())->toBe(TicketStatus::Resolved)
        ->and($ticket->fresh()->resolved_at)->not->toBeNull();
});

test('a status the enum does not offer is ignored', function () {
    $user = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(TicketShow::class, ['ticket' => $ticket])
        ->call('moveTo', 'archived');

    expect($ticket->fresh()->status())->toBe(TicketStatus::New);
});

test('assigning from the ticket page hands it over', function () {
    $user = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($user)->create();
    $agent = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TicketShow::class, ['ticket' => $ticket])
        ->call('assignTo', $agent->id);

    expect($ticket->fresh()->owner_id)->toBe($agent->id);
});

test('somebody who may not assign is refused by the method, not just the view', function () {
    $agent = ticketUser(['tickets.view', 'tickets.update']);
    $ticket = Ticket::factory()->ownedBy($agent)->create();

    Livewire::actingAs($agent)
        ->test(TicketShow::class, ['ticket' => $ticket])
        ->call('assignTo', User::factory()->create()->id)
        ->assertForbidden();

    expect($ticket->fresh()->owner_id)->toBe($agent->id);
});

test('a removed ticket still opens, and says so', function () {
    $user = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($user)->create(['subject' => 'Printer will not connect']);
    $ticket->delete();

    // A customer reading a reference down the telephone should not get a 404
    // because somebody removed it.
    Livewire::actingAs($user)
        ->test(TicketShow::class, ['ticket' => $ticket])
        ->assertOk()
        ->assertSee('Printer will not connect')
        ->assertSee('This ticket was removed.');
});

test('removing from the ticket page returns to the queue', function () {
    $user = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(TicketShow::class, ['ticket' => $ticket])
        ->call('delete')
        ->assertRedirect(route('tickets.index'));

    expect(Ticket::query()->withTrashed()->whereKey($ticket->id)->exists())->toBeTrue();
});

// -- Routes --------------------------------------------------------------------

test('the ticket pages are reachable', function () {
    $user = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($user)->create();

    $this->actingAs($user)->get(route('tickets.index'))->assertOk();
    $this->actingAs($user)->get(route('tickets.create'))->assertOk();
    $this->actingAs($user)->get(route('tickets.show', $ticket))->assertOk();
    $this->actingAs($user)->get(route('tickets.edit', $ticket))->assertOk();
});

test('a removed ticket still has a page', function () {
    $user = ticketAdmin();
    $ticket = Ticket::factory()->ownedBy($user)->create();
    $ticket->delete();

    // The route binds withTrashed for exactly this.
    $this->actingAs($user)->get(route('tickets.show', $ticket))->assertOk();
});
