<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Activities\Models\Activity;
use App\Domain\Leads\Models\Lead;
use App\Domain\Meta\Models\MetaPage;
use App\Domain\Social\Enums\ConversationStatus;
use App\Domain\Social\Enums\SocialChannel;
use App\Domain\Social\Models\SocialConversation;
use App\Domain\Social\Models\SocialMessage;
use App\Domain\Timeline\Models\Note;
use App\Livewire\Social\SocialInbox;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Task 12.8 — the inbox screen, and what a person can do from it.
 */
function socialAgent(array $permissions = ['social.inbox.view', 'social.inbox.reply', 'social.inbox.assign']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $model) {
        $user->givePermissionTo($model);
    }

    return $user->fresh();
}

test('the inbox lists conversations across every channel', function () {
    SocialConversation::factory()->create(['participant_name' => 'Dara Okafor']);
    SocialConversation::factory()->onChannel(SocialChannel::WhatsApp)->create(['participant_name' => 'Ife Bello']);

    Livewire::actingAs(socialAgent())
        ->test(SocialInbox::class)
        ->assertOk()
        // One screen, not one per channel.
        ->assertSee('Dara Okafor')
        ->assertSee('Ife Bello');
});

test('the channel is a filter rather than a second screen', function () {
    SocialConversation::factory()->create(['participant_name' => 'Dara Okafor']);
    SocialConversation::factory()->onChannel(SocialChannel::WhatsApp)->create(['participant_name' => 'Ife Bello']);

    Livewire::actingAs(socialAgent())
        ->test(SocialInbox::class)
        ->set('channel', SocialChannel::WhatsApp->value)
        ->assertDontSee('Dara Okafor')
        ->assertSee('Ife Bello');
});

test('opening a conversation marks it read and shows what was said', function () {
    $conversation = SocialConversation::factory()->unread(4)->create();

    SocialMessage::factory()->inConversation($conversation)->create(['body' => 'Do you deliver to Bristol?']);

    Livewire::actingAs(socialAgent())
        ->test(SocialInbox::class)
        ->call('select', $conversation->id)
        ->assertSee('Do you deliver to Bristol?');

    expect($conversation->fresh()?->unread_count)->toBe(0);
});

test('the reply box is replaced by Meta\'s reason when the window has closed', function () {
    $conversation = SocialConversation::factory()->windowClosed()->create();

    Livewire::actingAs(socialAgent())
        ->test(SocialInbox::class)
        ->call('select', $conversation->id)
        ->assertSee('window closed')
        ->assertSee('a message tag');
});

test('a reply sends from the screen', function () {
    $page = MetaPage::factory()->create(['page_id' => '1019283746']);

    $conversation = SocialConversation::factory()->create([
        'channel' => SocialChannel::Messenger->value,
        'channel_account_id' => $page->page_id,
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'mid.SENT'])]);

    Livewire::actingAs(socialAgent())
        ->test(SocialInbox::class)
        ->call('select', $conversation->id)
        ->set('reply', 'Yes, next-day to Bristol.')
        ->call('send')
        ->assertSee('Sent.');

    expect(SocialMessage::query()->outbound()->count())->toBe(1);
});

test('a refused send says why and keeps what was typed out of the thread', function () {
    $conversation = SocialConversation::factory()->windowClosed()->create();

    Http::fake();

    Livewire::actingAs(socialAgent())
        ->test(SocialInbox::class)
        ->call('select', $conversation->id)
        ->set('reply', 'Still interested?')
        ->call('send')
        ->assertSee('window closed');

    expect(SocialMessage::query()->count())->toBe(0);
});

test('claiming and releasing a conversation works from the screen', function () {
    $agent = socialAgent();
    $conversation = SocialConversation::factory()->create();

    Livewire::actingAs($agent)
        ->test(SocialInbox::class)
        ->call('select', $conversation->id)
        ->call('claim');

    expect($conversation->fresh()?->assigned_to_id)->toBe($agent->id);

    Livewire::actingAs($agent)
        ->test(SocialInbox::class)
        ->call('select', $conversation->id)
        ->call('release');

    expect($conversation->fresh()?->assigned_to_id)->toBeNull();
});

test('closing from the screen leaves it able to reopen', function () {
    $conversation = SocialConversation::factory()->create();

    Livewire::actingAs(socialAgent())
        ->test(SocialInbox::class)
        ->call('select', $conversation->id)
        ->call('close');

    expect($conversation->fresh()?->status())->toBe(ConversationStatus::Closed);
});

// -- The panel ---------------------------------------------------------------------

test('the panel makes a lead, carrying the channel\'s attribution', function () {
    $conversation = SocialConversation::factory()->create(['participant_name' => 'Dara Okafor']);

    Livewire::actingAs(socialAgent())
        ->test(SocialInbox::class)
        ->call('select', $conversation->id)
        ->call('createLead');

    $lead = Lead::query()->firstOrFail();

    expect($lead->first_name)->toBe('Dara')
        ->and($lead->last_name)->toBe('Okafor')
        ->and($lead->source)->toBe(SocialChannel::Messenger->leadSource())
        // The whole point: a lead that came from nowhere cannot be counted in
        // 12.13's figures or reported back in 12.12.
        ->and($lead->attribution()->source)->toBe(SocialChannel::Messenger->leadSource())
        ->and($conversation->fresh()?->lead_id)->toBe($lead->id);
});

test('a conversation that already has a lead does not get a second one', function () {
    $lead = Lead::factory()->create();
    $conversation = SocialConversation::factory()->forLead($lead)->create();

    Livewire::actingAs(socialAgent())
        ->test(SocialInbox::class)
        ->call('select', $conversation->id)
        ->call('createLead');

    expect(Lead::query()->count())->toBe(1);
});

test('a note from the panel lands on the linked record', function () {
    $lead = Lead::factory()->create();
    $conversation = SocialConversation::factory()->forLead($lead)->create();

    Livewire::actingAs(socialAgent())
        ->test(SocialInbox::class)
        ->call('select', $conversation->id)
        ->set('note', 'Wants delivery before Friday.')
        ->call('addNote');

    $note = Note::query()->firstOrFail();

    expect($note->body)->toBe('Wants delivery before Friday.')
        ->and($note->notable_id)->toBe($lead->id);
});

test('a note with nothing to hang off says so rather than vanishing', function () {
    $conversation = SocialConversation::factory()->create();

    Livewire::actingAs(socialAgent())
        ->test(SocialInbox::class)
        ->call('select', $conversation->id)
        ->set('note', 'Something')
        ->call('addNote')
        ->assertSee('Create a lead first');

    expect(Note::query()->count())->toBe(0);
});

test('a task from the panel is about the linked record', function () {
    $agent = socialAgent();
    // Owned by the agent: `CreateActivityAction` resolves the related record
    // through the actor's own access scope, so a task can only be attached to a
    // lead that person can actually see.
    $lead = Lead::factory()->ownedBy($agent)->create();
    $conversation = SocialConversation::factory()->forLead($lead)->create();

    Livewire::actingAs($agent)
        ->test(SocialInbox::class)
        ->call('select', $conversation->id)
        ->set('taskSubject', 'Ring about delivery')
        ->set('taskDueAt', now()->addDay()->toDateString())
        ->call('addTask');

    $activity = Activity::query()->firstOrFail();

    expect($activity->subject)->toBe('Ring about delivery')
        ->and($activity->related_id)->toBe($lead->id);
});

test('a task missing its day is refused in words', function () {
    $agent = socialAgent();
    $lead = Lead::factory()->ownedBy($agent)->create();
    $conversation = SocialConversation::factory()->forLead($lead)->create();

    Livewire::actingAs($agent)
        ->test(SocialInbox::class)
        ->call('select', $conversation->id)
        ->set('taskSubject', 'Ring about delivery')
        ->call('addTask')
        ->assertSee('a day to do it by');

    expect(Activity::query()->count())->toBe(0);
});

// -- Who may do what ----------------------------------------------------------------

test('reading does not let somebody reply', function () {
    $conversation = SocialConversation::factory()->create();

    Livewire::actingAs(socialAgent(['social.inbox.view']))
        ->test(SocialInbox::class)
        ->call('select', $conversation->id)
        ->assertSee('read this conversation but not reply')
        ->set('reply', 'Hello')
        ->call('send')
        ->assertForbidden();
});

test('replying does not let somebody reassign', function () {
    $conversation = SocialConversation::factory()->create();

    Livewire::actingAs(socialAgent(['social.inbox.view', 'social.inbox.reply']))
        ->test(SocialInbox::class)
        ->call('select', $conversation->id)
        ->call('claim')
        ->assertForbidden();
});

test('somebody without the permission cannot open the inbox', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(SocialInbox::class)
        ->assertForbidden();
});
