<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Activities\ActivityExportSource;
use App\Domain\Activities\ActivityFields;
use App\Domain\Activities\ActivityRelations;
use App\Domain\Activities\Enums\ActivityPriority;
use App\Domain\Activities\Enums\ActivityStatus;
use App\Domain\Activities\Enums\ActivityType;
use App\Domain\Activities\Enums\RecurrenceFrequency;
use App\Domain\Activities\Models\Activity;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Deals\Models\Deal;
use App\Domain\Shared\Enums\ExportFormat;
use App\Domain\Shared\Enums\FilterOperator;
use App\Domain\Shared\Enums\ViewMode;
use App\Domain\Shared\Exports\ExportRequest;
use App\Livewire\Activities\ActivitiesIndex;
use App\Livewire\Activities\ActivityForm;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow('2026-10-01 09:00:00');
});

// -- The list ------------------------------------------------------------------

test('the list screen renders what the viewer can see', function () {
    $user = activityAdmin();
    Activity::factory()->ownedBy($user)->create(['subject' => 'Call Dana back']);

    Livewire::actingAs($user)
        ->test(ActivitiesIndex::class)
        ->assertOk()
        ->assertSee('Call Dana back');
});

test('the list is refused without the view permission', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(ActivitiesIndex::class)
        ->assertForbidden();
});

test('an activity outside the access level never reaches the page', function () {
    $viewer = activityUser(['activities.view']);
    Activity::factory()->ownedBy($viewer)->create(['subject' => 'Mine']);
    Activity::factory()->create(['subject' => 'Somebody else']);

    Livewire::actingAs($viewer)
        ->test(ActivitiesIndex::class)
        ->assertSee('Mine')
        ->assertDontSee('Somebody else');
});

test('the list is soonest first when nobody has chosen a sort', function () {
    $user = activityAdmin();
    Activity::factory()->ownedBy($user)->create(['subject' => 'Later', 'due_at' => '2026-10-20 09:00']);
    Activity::factory()->ownedBy($user)->create(['subject' => 'Sooner', 'due_at' => '2026-10-02 09:00']);

    $rows = Livewire::actingAs($user)->test(ActivitiesIndex::class)->instance()
        ->rows();

    expect($rows->pluck('subject')->all())->toBe(['Sooner', 'Later']);
});

test('priority sorts by urgency, not alphabetically', function () {
    $user = activityAdmin();
    Activity::factory()->ownedBy($user)->withPriority(ActivityPriority::Low)->create(['subject' => 'Low one']);
    Activity::factory()->ownedBy($user)->withPriority(ActivityPriority::Urgent)->create(['subject' => 'Urgent one']);
    Activity::factory()->ownedBy($user)->withPriority(ActivityPriority::High)->create(['subject' => 'High one']);

    // A string column would come back "high, low, normal, urgent", which is
    // visibly wrong. This is why the enum is backed by a rank.
    $rows = Livewire::actingAs($user)
        ->test(ActivitiesIndex::class)
        ->call('sort', 'priority')
        ->call('sort', 'priority')
        ->instance()
        ->rows();

    expect($rows->pluck('subject')->all())->toBe(['Urgent one', 'High one', 'Low one']);
});

// -- Quick filters -------------------------------------------------------------

test('the quick filters narrow the list', function (string $chip, string $expected) {
    $user = activityAdmin();

    Activity::factory()->ownedBy($user)->create(['subject' => 'Due today', 'due_at' => '2026-10-01 15:00']);
    Activity::factory()->ownedBy($user)->create(['subject' => 'Late', 'due_at' => '2026-09-20 09:00']);
    Activity::factory()->ownedBy($user)->completed()->create(['subject' => 'Finished', 'due_at' => '2026-10-03 09:00']);
    Activity::factory()->create(['subject' => 'Not mine', 'due_at' => '2026-10-02 09:00']);

    $rows = Livewire::actingAs($user)
        ->test(ActivitiesIndex::class)
        ->call('setQuickFilter', $chip)
        ->instance()
        ->rows();

    expect($rows->pluck('subject')->all())->toContain($expected);
})->with([
    'today' => ['today', 'Due today'],
    'overdue' => ['overdue', 'Late'],
    'completed' => ['completed', 'Finished'],
]);

test('the mine chip excludes what belongs to somebody else', function () {
    $user = activityAdmin();
    Activity::factory()->ownedBy($user)->create(['subject' => 'Mine']);
    Activity::factory()->create(['subject' => 'Theirs']);

    $rows = Livewire::actingAs($user)
        ->test(ActivitiesIndex::class)
        ->call('setQuickFilter', 'mine')
        ->instance()
        ->rows();

    expect($rows->pluck('subject')->all())->toBe(['Mine']);
});

test('a chip the screen does not offer is ignored', function () {
    Livewire::actingAs(activityAdmin())
        ->test(ActivitiesIndex::class)
        ->call('setQuickFilter', 'everything')
        ->assertSet('quickFilter', '');
});

test('pressing the same chip again clears it', function () {
    Livewire::actingAs(activityAdmin())
        ->test(ActivitiesIndex::class)
        ->call('setQuickFilter', 'overdue')
        ->assertSet('quickFilter', 'overdue')
        ->call('setQuickFilter', 'overdue')
        ->assertSet('quickFilter', '');
});

// -- Totals --------------------------------------------------------------------

test('the totals describe the whole filtered set, not the page', function () {
    $user = activityAdmin();
    Activity::factory()->ownedBy($user)->count(3)->create(['due_at' => '2026-10-10 09:00']);
    Activity::factory()->ownedBy($user)->count(2)->create(['due_at' => '2026-09-01 09:00']);
    Activity::factory()->ownedBy($user)->completed()->create(['due_at' => '2026-10-05 09:00']);

    $totals = Livewire::actingAs($user)
        ->test(ActivitiesIndex::class)
        ->set('perPage', 25)
        ->instance()
        ->totals();

    expect($totals['count'])->toBe(6)
        ->and($totals['open'])->toBe(5)
        ->and($totals['overdue'])->toBe(2)
        ->and($totals['completed'])->toBe(1);
});

test('the overdue total makes the same all-day allowance the row does', function () {
    $user = activityAdmin();
    Activity::factory()->ownedBy($user)->allDay('2026-10-01')->create();
    Activity::factory()->ownedBy($user)->allDay('2026-09-30')->create();

    $totals = Livewire::actingAs($user)->test(ActivitiesIndex::class)->instance()->totals();

    // Due "today" all day is not late at nine in the morning.
    expect($totals['overdue'])->toBe(1);
});

// -- The board -----------------------------------------------------------------

test('the board groups by status', function () {
    $columns = Livewire::actingAs(activityAdmin())
        ->test(ActivitiesIndex::class)
        ->instance();

    expect($columns->dataViewKanbanField())->toBe('status')
        ->and(array_column($columns->dataViewKanbanColumns(), 'value'))
        ->toBe(['open', 'completed', 'cancelled']);
});

test('a drag into the completed column completes it, and says it moved', function () {
    $user = activityAdmin();
    $activity = Activity::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(ActivitiesIndex::class)
        ->call('setViewMode', ViewMode::Kanban->value)
        ->call('moveCard', $activity->id, ActivityStatus::Completed->value)
        ->assertReturned(true);

    // Through the action, not by writing the column: it is the action that owns
    // completed_at.
    expect($activity->fresh()->isCompleted())->toBeTrue()
        ->and($activity->fresh()->completed_at)->not->toBeNull();
});

test('a drag back into open reopens it', function () {
    $user = activityAdmin();
    $activity = Activity::factory()->ownedBy($user)->completed()->create();

    Livewire::actingAs($user)
        ->test(ActivitiesIndex::class)
        ->call('moveCard', $activity->id, ActivityStatus::Open->value)
        ->assertReturned(true);

    expect($activity->fresh()->isOpen())->toBeTrue()
        ->and($activity->fresh()->completed_at)->toBeNull();
});

test('a drop back into the column it came from reports no move', function () {
    $user = activityAdmin();
    $activity = Activity::factory()->ownedBy($user)->create();

    // Not an error, but reporting a move would flash a change that did not
    // happen — and here it would push completed_at forward.
    Livewire::actingAs($user)
        ->test(ActivitiesIndex::class)
        ->call('moveCard', $activity->id, ActivityStatus::Open->value)
        ->assertReturned(false);
});

test('a column value the board does not offer is refused', function () {
    $user = activityAdmin();
    $activity = Activity::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(ActivitiesIndex::class)
        ->call('moveCard', $activity->id, 'archived')
        ->assertReturned(false);

    expect($activity->fresh()->status())->toBe(ActivityStatus::Open);
});

test('a card outside the access level cannot be dragged by guessing its id', function () {
    $theirs = Activity::factory()->create();
    $user = activityUser(['activities.view', 'activities.update']);

    Livewire::actingAs($user)
        ->test(ActivitiesIndex::class)
        ->call('moveCard', $theirs->id, ActivityStatus::Completed->value)
        ->assertReturned(false);

    expect($theirs->fresh()->isOpen())->toBeTrue();
});

// -- Row actions ---------------------------------------------------------------

test('the tick on a row completes it', function () {
    $user = activityAdmin();
    $activity = Activity::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(ActivitiesIndex::class)
        ->call('complete', $activity->id);

    expect($activity->fresh()->isCompleted())->toBeTrue();
});

test('somebody who may only look is not offered the tick', function () {
    $viewer = activityUser(['activities.view']);
    $activity = Activity::factory()->ownedBy($viewer)->create(['subject' => 'Call Dana back']);

    $screen = Livewire::actingAs($viewer)->test(ActivitiesIndex::class);

    expect($screen->instance()->canAct())->toBeFalse();

    $screen->assertSee('Call Dana back')
        ->assertDontSeeHtml('wire:click="complete('.$activity->id.')"');
});

test('completing a selection only touches what the person may update', function () {
    $user = activityAdmin();
    $mine = Activity::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(ActivitiesIndex::class)
        ->call('toggleSelection', $mine->id)
        ->call('completeSelected');

    expect($mine->fresh()->isCompleted())->toBeTrue();
});

test('removing a selection keeps the rows', function () {
    $user = activityAdmin();
    $activity = Activity::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(ActivitiesIndex::class)
        ->call('toggleSelection', $activity->id)
        ->call('deleteSelected');

    expect(Activity::query()->whereKey($activity->id)->exists())->toBeFalse()
        ->and(Activity::query()->withTrashed()->whereKey($activity->id)->exists())->toBeTrue();
});

// -- Filtering and searching ---------------------------------------------------

test('search covers the subject, the notes and the location', function (string $term, string $expected) {
    $user = activityAdmin();
    Activity::factory()->ownedBy($user)->create(['subject' => 'Renewal call', 'description' => null]);
    Activity::factory()->ownedBy($user)->create(['subject' => 'Kickoff', 'description' => 'Bring the deck']);
    Activity::factory()->ownedBy($user)->meeting()->create(['subject' => 'Review', 'location' => 'Their office']);

    $rows = Livewire::actingAs($user)
        ->test(ActivitiesIndex::class)
        ->set('search', $term)
        ->instance()
        ->rows();

    expect($rows->pluck('subject')->all())->toBe([$expected]);
})->with([
    'subject' => ['Renewal', 'Renewal call'],
    'notes' => ['deck', 'Kickoff'],
    'location' => ['Their office', 'Review'],
]);

test('the model scope and the list search return the same rows', function () {
    $user = activityAdmin();
    Activity::factory()->ownedBy($user)->count(3)->create(['subject' => 'Renewal call']);
    Activity::factory()->ownedBy($user)->create(['subject' => 'Something else']);

    $listed = Livewire::actingAs($user)
        ->test(ActivitiesIndex::class)
        ->set('search', 'Renewal')
        ->instance()
        ->rows()
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    $scoped = Activity::query()->visibleTo($user)->search('Renewal')->pluck('id')->sort()->values()->all();

    // A queued export rebuilds its query from the model scope, so the two
    // disagreeing would mean an export containing rows the list never showed.
    expect($listed)->toBe($scoped);
});

test('the filter builder filters on a declared field', function () {
    $user = activityAdmin();
    Activity::factory()->ownedBy($user)->call()->create(['subject' => 'A call']);
    Activity::factory()->ownedBy($user)->create(['subject' => 'A task']);

    $rows = Livewire::actingAs($user)
        ->test(ActivitiesIndex::class)
        ->set('filters.conditions', [[
            'field' => 'type',
            'operator' => FilterOperator::Equals->value,
            'value' => ActivityType::Call->value,
            'value2' => null,
        ]])
        ->instance()
        ->rows();

    expect($rows->pluck('subject')->all())->toBe(['A call']);
});

test('a field the screen does not declare never reaches the query', function () {
    $user = activityAdmin();
    Activity::factory()->ownedBy($user)->count(2)->create();

    $rows = Livewire::actingAs($user)
        ->test(ActivitiesIndex::class)
        ->set('filters.conditions', [[
            'field' => 'reminder_sent_at',
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
    $columns = Schema::getColumnListing('activities');

    foreach (ActivityFields::filters() as $field) {
        expect($columns)->toContain($field->column());
    }
});

// -- Export --------------------------------------------------------------------

test('the export is offered only to somebody who may export', function () {
    expect(Livewire::actingAs(activityAdmin())->test(ActivitiesIndex::class)->instance()->dataViewExportSource())
        ->not->toBeNull()
        ->and(Livewire::actingAs(activityUser(['activities.view']))->test(ActivitiesIndex::class)
            ->instance()->dataViewExportSource())
        ->toBeNull();
});

test('a queued export re-applies the access scope', function () {
    $viewer = activityUser(['activities.view', 'activities.export']);
    $mine = Activity::factory()->ownedBy($viewer)->create();
    Activity::factory()->create();

    $request = new ExportRequest(
        source: ActivityExportSource::class,
        format: ExportFormat::Csv,
        module: 'activities',
        columns: ['subject' => 'Activity'],
        userId: $viewer->id,
    );

    // A queued export runs with no session, so forgetting visibleTo() here
    // would send somebody rows they could not see.
    expect(app(ActivityExportSource::class)->exportQuery($request)->pluck('id')->all())->toBe([$mine->id]);
});

test('the export writes labels, not stored ranks', function () {
    $user = activityAdmin();
    $account = Account::factory()->create(['name' => 'Acme']);
    $activity = Activity::factory()->ownedBy($user)->call()
        ->withPriority(ActivityPriority::Urgent)
        ->about($account)
        ->create(['subject' => 'Renewal call', 'due_at' => '2026-10-01 14:30']);

    $request = new ExportRequest(
        source: ActivityExportSource::class,
        format: ExportFormat::Csv,
        module: 'activities',
        columns: [
            'subject' => 'Activity',
            'type' => 'Type',
            'priority' => 'Priority',
            'status' => 'Status',
            'related' => 'About',
            'due_at' => 'Due',
        ],
        userId: $user->id,
    );

    $row = app(ActivityExportSource::class)->exportRow($activity, $request);

    // A spreadsheet column of 4s means nothing to whoever opens it.
    expect($row)->toBe([
        'Renewal call',
        'Call',
        'Urgent',
        'Open',
        'Account: Acme',
        '2026-10-01 14:30',
    ]);
});

// -- The form ------------------------------------------------------------------

test('the form creates an activity', function () {
    $user = activityAdmin();

    Livewire::actingAs($user)
        ->test(ActivityForm::class)
        ->set('type', ActivityType::Call->value)
        ->set('subject', 'Call Dana back')
        ->set('due_date', '2026-10-05')
        ->set('due_time', '11:30')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('activities.index'));

    $activity = Activity::query()->firstOrFail();

    expect($activity->subject)->toBe('Call Dana back')
        ->and($activity->due_at->format('Y-m-d H:i'))->toBe('2026-10-05 11:30')
        ->and($activity->owner_id)->toBe($user->id);
});

test('the form has no status control', function () {
    // The complete, reopen and cancel actions own status. A field here would be
    // a second path to "done".
    $user = activityAdmin();
    $activity = Activity::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(ActivityForm::class, ['activity' => $activity])
        ->assertDontSeeHtml('wire:model="status"')
        ->assertDontSeeHtml('wire:model.live="status"');
});

test('the form insists on a subject and a due date', function () {
    Livewire::actingAs(activityAdmin())
        ->test(ActivityForm::class)
        ->set('subject', '')
        ->set('due_date', '')
        ->call('save')
        ->assertHasErrors(['subject' => 'required', 'due_date' => 'required']);
});

test('an all-day activity needs no time', function () {
    Livewire::actingAs(activityAdmin())
        ->test(ActivityForm::class)
        ->set('subject', 'Submit the paperwork')
        ->set('due_date', '2026-10-05')
        ->set('all_day', true)
        ->set('due_time', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(Activity::query()->firstOrFail()->due_at->format('H:i'))->toBe('00:00');
});

test('a timed activity does need one', function () {
    Livewire::actingAs(activityAdmin())
        ->test(ActivityForm::class)
        ->set('subject', 'Call Dana back')
        ->set('due_date', '2026-10-05')
        ->set('all_day', false)
        ->set('due_time', '')
        ->call('save')
        ->assertHasErrors(['due_time' => 'required']);
});

test('a repeat count has to be more than one', function () {
    // "Repeats once" is not a series, and a count of one would produce a master
    // and nothing else.
    Livewire::actingAs(activityAdmin())
        ->test(ActivityForm::class)
        ->set('subject', 'Weekly check-in')
        ->set('due_date', '2026-10-05')
        ->set('due_time', '09:00')
        ->set('recurrence_frequency', 'weekly')
        ->set('recurrence_end', 'after')
        ->set('recurrence_count', '1')
        ->call('save')
        ->assertHasErrors(['recurrence_count']);
});

test('a repeat end date has to be after the first occurrence', function () {
    Livewire::actingAs(activityAdmin())
        ->test(ActivityForm::class)
        ->set('subject', 'Weekly check-in')
        ->set('due_date', '2026-10-05')
        ->set('due_time', '09:00')
        ->set('recurrence_frequency', 'weekly')
        ->set('recurrence_end', 'on')
        ->set('recurrence_until', '2026-10-01')
        ->call('save')
        ->assertHasErrors(['recurrence_until']);
});

test('saving a series materialises the next few occurrences straight away', function () {
    Livewire::actingAs(activityAdmin())
        ->test(ActivityForm::class)
        ->set('subject', 'Weekly check-in')
        ->set('due_date', '2026-10-05')
        ->set('due_time', '09:00')
        ->set('recurrence_frequency', 'weekly')
        ->set('recurrence_end', 'after')
        ->set('recurrence_count', '4')
        ->call('save')
        ->assertHasNoErrors();

    // Waiting for the nightly sweep would mean somebody who just set up a
    // weekly call sees nothing but the first one.
    expect(Activity::query()->whereNotNull('recurrence_parent_id')->count())->toBe(3);
});

test('a record the person cannot reach is refused by the form as well as the action', function () {
    $stranger = Account::factory()->create();
    $user = activityUser(['activities.view', 'activities.create', 'accounts.view']);

    Livewire::actingAs($user)
        ->test(ActivityForm::class)
        ->set('subject', 'Call them')
        ->set('due_date', '2026-10-05')
        ->set('due_time', '09:00')
        ->set('related_module', 'accounts')
        ->set('related_id', (string) $stranger->id)
        ->call('save')
        ->assertHasErrors(['related_id']);
});

test('a module outside the registry is refused by validation', function () {
    Livewire::actingAs(activityAdmin())
        ->test(ActivityForm::class)
        ->set('subject', 'Call them')
        ->set('due_date', '2026-10-05')
        ->set('due_time', '09:00')
        ->set('related_module', 'users')
        ->set('related_id', '1')
        ->call('save')
        ->assertHasErrors(['related_module']);
});

test('choosing another kind of record clears the one already chosen', function () {
    $account = Account::factory()->create();

    Livewire::actingAs(activityAdmin())
        ->test(ActivityForm::class)
        ->set('related_module', 'accounts')
        ->set('related_id', (string) $account->id)
        ->set('related_module', 'deals')
        ->assertSet('related_id', null);
});

test('the record picker only offers what the person can see', function () {
    $viewer = activityUser(['activities.view', 'activities.create', 'accounts.view']);
    $mine = Account::factory()->create(['name' => 'Mine Ltd', 'owner_id' => $viewer->id]);
    Account::factory()->create(['name' => 'Theirs Ltd']);

    $options = Livewire::actingAs($viewer)
        ->test(ActivityForm::class)
        ->set('related_module', 'accounts')
        ->instance()
        ->searchRelated();

    expect(array_column($options['options'], 'label'))->toBe([$mine->name]);
});

test('the picker copes with the null Tom Select sends on a preload', function () {
    // A string parameter here turns a preload into a silent 500.
    $options = Livewire::actingAs(activityAdmin())
        ->test(ActivityForm::class)
        ->set('related_module', 'contacts')
        ->instance()
        ->searchRelated(null, null);

    expect($options)->toHaveKeys(['options', 'hasMore']);
});

test('the form opens pre-filled from a record page, and refuses one the person cannot see', function () {
    $user = activityAdmin();
    $deal = Deal::factory()->create();

    // withQueryParams, not mount arguments: these are #[Url] properties, and
    // Livewire hydrates them from the query string.
    Livewire::actingAs($user)
        ->withQueryParams(['about' => 'deals', 'record' => (string) $deal->id])
        ->test(ActivityForm::class)
        ->assertSet('related_module', 'deals')
        ->assertSet('related_id', (string) $deal->id);

    $stranger = Deal::factory()->create();
    $limited = activityUser(['activities.view', 'activities.create', 'deals.view']);

    Livewire::actingAs($limited)
        ->withQueryParams(['about' => 'deals', 'record' => (string) $stranger->id])
        ->test(ActivityForm::class)
        ->assertSet('related_module', null)
        ->assertSet('related_id', null);
});

test('editing loads what is stored, including the repeat rule', function () {
    $user = activityAdmin();
    $contact = Contact::factory()->create();
    $activity = Activity::factory()->ownedBy($user)->meeting()->about($contact)
        ->repeating(RecurrenceFrequency::Monthly, interval: 2, count: 6)
        ->create(['subject' => 'Monthly review', 'due_at' => '2026-10-05 14:00']);

    Livewire::actingAs($user)
        ->test(ActivityForm::class, ['activity' => $activity])
        ->assertSet('subject', 'Monthly review')
        ->assertSet('due_date', '2026-10-05')
        ->assertSet('due_time', '14:00')
        ->assertSet('recurrence_frequency', 'monthly')
        ->assertSet('recurrence_interval', '2')
        ->assertSet('recurrence_end', 'after')
        ->assertSet('recurrence_count', '6')
        ->assertSet('related_module', 'contacts')
        ->assertSet('related_id', (string) $contact->id);
});

test('the form can mark an activity done, and put it back', function () {
    $user = activityAdmin();
    $activity = Activity::factory()->ownedBy($user)->create();

    Livewire::actingAs($user)
        ->test(ActivityForm::class, ['activity' => $activity])
        ->call('complete')
        ->assertRedirect(route('activities.index'));

    expect($activity->fresh()->isCompleted())->toBeTrue();

    Livewire::actingAs($user)
        ->test(ActivityForm::class, ['activity' => $activity->fresh()])
        ->call('reopen');

    expect($activity->fresh()->isOpen())->toBeTrue();
});

test('the form is refused to somebody who may not create', function () {
    Livewire::actingAs(activityUser(['activities.view']))
        ->test(ActivityForm::class)
        ->assertForbidden();
});

test('somebody without the assign permission is not offered the owner field', function () {
    $user = activityUser(['activities.view', 'activities.update']);
    $activity = Activity::factory()->ownedBy($user)->create();

    $screen = Livewire::actingAs($user)->test(ActivityForm::class, ['activity' => $activity]);

    expect($screen->instance()->canAssign($activity))->toBeFalse();

    $screen->assertDontSeeHtml('wire:model="owner_id"');
});

test('an owner submitted by somebody who may not assign is ignored', function () {
    $user = activityUser(['activities.view', 'activities.update']);
    $activity = Activity::factory()->ownedBy($user)->create();
    $colleague = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ActivityForm::class, ['activity' => $activity])
        ->set('owner_id', (string) $colleague->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($activity->fresh()->owner_id)->toBe($user->id);
});

// -- The route and the navigation ----------------------------------------------

test('the routes resolve', function (string $name) {
    $user = activityAdmin();
    $activity = Activity::factory()->ownedBy($user)->create();

    $url = $name === 'activities.edit'
        ? route($name, ['activity' => $activity->id])
        : route($name);

    $this->actingAs($user)->get($url)->assertOk();
})->with(['activities.index', 'activities.create', 'activities.edit']);

test('the module appears in the navigation for somebody who may see it', function () {
    $this->actingAs(activityAdmin())
        ->get(route('activities.index'))
        ->assertOk()
        ->assertSee(route('activities.index'));

    // And not for somebody who may not.
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('activities.index'));
});

// -- The registry --------------------------------------------------------------

test('every module an activity can be about can be queried and labelled', function (string $module) {
    $user = activityAdmin();

    // A branch missing from visibleQuery() would make a module in the registry
    // unusable in the picker while still being offered by it.
    expect(ActivityRelations::visibleQuery($module, $user))->not->toBeNull()
        ->and(ActivityRelations::modelClass($module))->not->toBeNull()
        ->and(ActivityRelations::morphClass($module))->not->toBeNull()
        ->and(ActivityRelations::options())->toHaveKey($module);
})->with(fn () => ActivityRelations::keys());

test('a module the registry does not list resolves to nothing', function () {
    expect(ActivityRelations::visibleQuery('users', activityAdmin()))->toBeNull()
        ->and(ActivityRelations::modelClass('users'))->toBeNull()
        ->and(ActivityRelations::has('users'))->toBeFalse();
});
