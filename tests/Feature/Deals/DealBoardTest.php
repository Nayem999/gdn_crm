<?php

use App\Domain\Accounts\Enums\AccountSize;
use App\Domain\Accounts\Models\Account;
use App\Domain\Deals\Actions\CloseDealAction;
use App\Domain\Deals\Enums\DealCloseReason;
use App\Domain\Deals\Models\Deal;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Enums\ViewMode;
use App\Livewire\Accounts\AccountsIndex;
use App\Livewire\Deals\DealsIndex;
use App\Livewire\Leads\LeadsIndex;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Cache::flush();
});

/**
 * The board, ready to drag on.
 *
 * The return type is fully qualified: `use Livewire\Livewire` imports that class
 * as `Livewire`, so a relative `Livewire\Features\...` resolves through the alias
 * to `Livewire\Livewire\Features\...` and fails at runtime.
 */
function dealBoard(?User $user = null): Testable
{
    return Livewire::actingAs($user ?? dealAdmin())
        ->test(DealsIndex::class)
        ->call('setViewMode', ViewMode::Kanban->value);
}

// -- A drag updates the stage --------------------------------------------------

test('a drag moves the deal and reports that it moved', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    $moved = Livewire::actingAs($user)
        ->test(DealsIndex::class)
        ->call('moveCard', $deal->id, 'negotiation')
        ->assertReturned(true);

    expect($deal->fresh()->stage)->toBe('negotiation');
});

test('the answer the board acts on is the return value, not a guess', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    // The drag awaits this value and puts the card back on false. Morphdom
    // relocating a keyed node between two different parents is exactly what
    // this exists not to depend on.
    Livewire::actingAs($user)
        ->test(DealsIndex::class)
        ->call('moveCard', $deal->id, 'renewal_due')
        ->assertReturned(false);
});

// -- ...logs history -----------------------------------------------------------

test('a drag writes the stage change to the audit trail, with who did it', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    Livewire::actingAs($user)->test(DealsIndex::class)->call('moveCard', $deal->id, 'negotiation');

    $entry = Activity::query()
        ->where('subject_type', (new Deal)->getMorphClass())
        ->where('subject_id', $deal->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->properties['attributes']['stage'] ?? null)->toBe('negotiation')
        ->and($entry->properties['old']['stage'] ?? null)->toBe('scoping')
        // "Who moved this and when" is the question the trail answers.
        ->and($entry->causer_id)->toBe($user->id);
});

test('a refused drag writes nothing to the trail', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    $before = Activity::query()->where('subject_id', $deal->id)->count();

    Livewire::actingAs($user)->test(DealsIndex::class)->call('moveCard', $deal->id, 'renewal_due');

    expect(Activity::query()->where('subject_id', $deal->id)->count())->toBe($before);
});

test('a drag into a closing stage stamps the close on the trail too', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    Livewire::actingAs($user)->test(DealsIndex::class)->call('moveCard', $deal->id, 'closed_won');

    $entry = Activity::query()->where('subject_id', $deal->id)->where('event', 'updated')->latest('id')->first();

    expect($deal->fresh()->closed_at)->not->toBeNull()
        ->and($entry->properties['attributes'])->toHaveKey('closed_at');
});

// -- ...reverts on failure -----------------------------------------------------

test('a drag onto a stage the pipeline does not have is refused and says why', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    Livewire::actingAs($user)
        ->test(DealsIndex::class)
        ->call('moveCard', $deal->id, 'renewal_due')
        ->assertReturned(false)
        ->assertDispatched('notify', type: 'error');

    expect($deal->fresh()->stage)->toBe('scoping');
});

test('dropping a card back into its own column changes nothing and reports no move', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    // Reporting a move here would make the board flash a change that did not
    // happen, and would push the closing stamp forward on a closed deal.
    Livewire::actingAs($user)
        ->test(DealsIndex::class)
        ->call('moveCard', $deal->id, 'scoping')
        ->assertReturned(false);

    expect($deal->fresh()->stage)->toBe('scoping');
});

test('a closed deal dropped back into its own column keeps its closing stamp', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    app(CloseDealAction::class)($deal, 'closed_won', DealCloseReason::BestFit);
    $stampedAt = $deal->fresh()->closed_at;

    Carbon\Carbon::setTestNow(now()->addHour());
    Livewire::actingAs($user)->test(DealsIndex::class)->call('moveCard', $deal->id, 'closed_won');
    Carbon\Carbon::setTestNow();

    expect($deal->fresh()->closed_at->toDateTimeString())->toBe($stampedAt->toDateTimeString())
        ->and($deal->fresh()->closeReason())->toBe(DealCloseReason::BestFit);
});

test('a deal outside the viewer access level cannot be dragged at all', function () {
    $pipeline = dealPipeline();

    $owner = dealUser(['deals.view', 'deals.update']);
    $peer = dealUser(['deals.view', 'deals.update']);
    $deal = Deal::factory()->ownedBy($owner)->onPipeline($pipeline)->create();

    Livewire::actingAs($peer)
        ->test(DealsIndex::class)
        ->call('moveCard', $deal->id, 'negotiation')
        ->assertReturned(false);

    expect($deal->fresh()->stage)->toBe('scoping');
});

test('a drag needs deals.update, and a reader is refused', function () {
    $pipeline = dealPipeline();
    $user = dealUser(['deals.view']);
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    Livewire::actingAs($user)
        ->test(DealsIndex::class)
        ->call('moveCard', $deal->id, 'negotiation')
        ->assertForbidden();

    expect($deal->fresh()->stage)->toBe('scoping');
});

test('the kit refuses a column value the board does not offer', function () {
    $pipeline = dealPipeline();
    $user = dealAdmin();
    $deal = Deal::factory()->ownedBy($user)->onPipeline($pipeline)->create();

    // Nothing from the browser names a stage without passing through the
    // board's own column list first.
    Livewire::actingAs($user)
        ->test(DealsIndex::class)
        ->call('moveCard', $deal->id, 'stage; drop table deals')
        ->assertReturned(false);

    expect($deal->fresh()->stage)->toBe('scoping');
});

// -- Column totals -------------------------------------------------------------

test('each column carries the count and the summed value of the whole filtered set', function () {
    $pipeline = dealPipeline();
    $user = dealUserSeeingEverything(['deals.view']);

    Deal::factory()->count(3)->onPipeline($pipeline, 'scoping')->create(['value' => '1000']);
    Deal::factory()->count(2)->onPipeline($pipeline, 'negotiation')->create(['value' => '5000']);

    $board = dealBoard($user)->instance();
    $totals = $board->kanbanTotals();

    expect($totals['scoping']['count'])->toBe(3)
        ->and($totals['scoping']['sum'])->toBe(3000.0)
        ->and($totals['negotiation']['count'])->toBe(2)
        ->and($totals['negotiation']['sum'])->toBe(10000.0);
});

test('the totals describe the data, not the page of cards on screen', function () {
    $pipeline = dealPipeline();
    $user = dealUserSeeingEverything(['deals.view']);

    Deal::factory()->count(DealsIndex::KANBAN_PAGE + 5)
        ->onPipeline($pipeline, 'scoping')
        ->create(['value' => '100']);

    $board = dealBoard($user)->instance();

    // A board built from one page would show an arbitrary slice and a count
    // that is simply wrong.
    expect($board->kanbanCards('scoping'))->toHaveCount(DealsIndex::KANBAN_PAGE)
        ->and($board->kanbanTotals()['scoping']['count'])->toBe(DealsIndex::KANBAN_PAGE + 5)
        ->and($board->hasMoreKanbanCards('scoping'))->toBeTrue();
});

test('the totals follow the filters and the quick chips', function () {
    $pipeline = dealPipeline();
    $user = dealUserSeeingEverything(['deals.view']);

    $mine = Deal::factory()->ownedBy($user)->onPipeline($pipeline, 'scoping')->create(['value' => '1000']);
    Deal::factory()->onPipeline($pipeline, 'scoping')->create(['value' => '9000']);

    $board = dealBoard($user);
    expect($board->instance()->kanbanTotals()['scoping']['count'])->toBe(2);

    $board->call('setQuickFilter', 'mine');

    expect($board->instance()->kanbanTotals()['scoping'])
        ->toMatchArray(['count' => 1, 'sum' => 1000.0]);
});

test('loading more in one column leaves the others where they were', function () {
    $pipeline = dealPipeline();
    $user = dealUserSeeingEverything(['deals.view']);

    Deal::factory()->count(DealsIndex::KANBAN_PAGE + 5)->onPipeline($pipeline, 'scoping')->create();
    Deal::factory()->count(DealsIndex::KANBAN_PAGE + 5)->onPipeline($pipeline, 'negotiation')->create();

    $board = dealBoard($user)->call('loadMoreKanban', 'scoping');

    expect($board->instance()->kanbanLimitFor('scoping'))->toBe(DealsIndex::KANBAN_PAGE * 2)
        ->and($board->instance()->kanbanLimitFor('negotiation'))->toBe(DealsIndex::KANBAN_PAGE);
});

test('a column value the board does not offer cannot be loaded more of', function () {
    dealPipeline();

    $board = dealBoard()->call('loadMoreKanban', 'renewal_due');

    expect($board->instance()->kanbanLimitFor('renewal_due'))->toBe(DealsIndex::KANBAN_PAGE);
});

// -- The optimistic layer the browser needs ------------------------------------

test('the board gives each column the hooks the drag adjusts', function () {
    $pipeline = dealPipeline();
    $user = dealUserSeeingEverything(['deals.view']);

    Deal::factory()->count(2)->onPipeline($pipeline, 'scoping')->create();

    $html = dealBoard($user)->html();

    // The drag nudges data-board-count while the move is in flight, then the
    // re-render sets it back from the grouped query.
    expect($html)->toContain('data-board-column="scoping"')
        ->and($html)->toContain('data-board-count="2"')
        ->and($html)->toContain('data-card-id=');
});

test('the drag records where a card came from and puts it back on refusal', function () {
    $js = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/app.js');

    // Pinned because no PHP test can execute the drag: these are the pieces
    // that make a refused move go back rather than sit in the wrong column.
    expect($js)->toContain('_kanbanOrigin')
        ->and($js)->toContain('onStart:')
        ->and($js)->toContain('origin.parent.insertBefore(card, next)')
        // A card already waiting on the server must not be dragged again.
        ->and($js)->toContain("filter: '[data-card-pending]'")
        ->and($js)->toContain('data-card-pending')
        // The move is awaited, so the answer is the server's and not assumed.
        ->and($js)->toContain('await this.$wire.call(config.method');
});

// -- A card never repeats the column it is sitting in --------------------------

test('the board card does not repeat the field the board groups by', function () {
    $pipeline = dealPipeline();
    $user = dealUserSeeingEverything(['deals.view']);

    Deal::factory()->onPipeline($pipeline, 'scoping')->create(['name' => 'Acme rollout']);

    $html = dealBoard($user)->html();

    // Every card in the Scoping column would otherwise carry the line
    // "Stage: Scoping" — the column header, repeated on each of its own cards.
    expect($html)->toContain('Acme rollout')
        ->and($html)->not->toContain('Stage: ');
});

test('the leads board card does not repeat the status either', function () {
    $user = leadUser();
    Lead::factory()->ownedBy($user)->status(LeadStatus::New)->named('Dana', 'Scully')->create();

    $html = Livewire::actingAs($user)
        ->test(LeadsIndex::class)
        ->call('setViewMode', ViewMode::Kanban->value)
        ->html();

    expect($html)->toContain('Dana Scully')
        ->and($html)->not->toContain('Status: ');
});

test('the accounts board card does not repeat the size band either', function () {
    $user = accountUser();
    Account::factory()->ownedBy($user)->size(AccountSize::Large)->create(['name' => 'Big Co']);

    $html = Livewire::actingAs($user)
        ->test(AccountsIndex::class)
        ->call('setViewMode', ViewMode::Kanban->value)
        ->html();

    expect($html)->toContain('Big Co')
        ->and($html)->not->toContain('Size: ');
});
