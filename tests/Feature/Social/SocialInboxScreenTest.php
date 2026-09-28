<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Activities\Models\Activity;
use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Domain\Leads\Models\Lead;
use App\Domain\Meta\Models\MetaPage;
use App\Domain\Social\Enums\ConversationStatus;
use App\Domain\Social\Enums\SocialChannel;
use App\Domain\Social\Models\SocialConversation;
use App\Domain\Social\Models\SocialMessage;
use App\Domain\Timeline\Models\Note;
use App\Livewire\Leads\LeadForm;
use App\Livewire\Leads\LeadsIndex;
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

// -- Converting a chat to a lead ----------------------------------------------

test('the panel offers to convert the chat and to list the leads it produced', function () {
    $agent = socialAgent(['social.inbox.view', 'social.inbox.assign', 'leads.view', 'leads.create']);
    $conversation = SocialConversation::factory()->create();

    Livewire::actingAs($agent)
        ->test(SocialInbox::class)
        ->call('select', $conversation->id)
        ->assertSee('Convert to lead')
        ->assertSee(route('leads.create', ['conversation' => $conversation->id]), escape: false)
        ->assertSee('Leads (0)')
        ->assertSee(route('leads.index', ['conversation' => $conversation->id]), escape: false);

    // Selecting the chat made nothing.
    expect(Lead::query()->count())->toBe(0);
});

test('converting opens the lead form filled in from the chat', function () {
    $agent = socialAgent(['social.inbox.view', 'leads.view', 'leads.create']);
    $conversation = SocialConversation::factory()->create([
        'channel' => SocialChannel::WhatsApp->value,
        'participant_name' => 'Dara Okafor',
        'participant_handle' => '+8801711000000',
    ]);
    SocialMessage::factory()->create([
        'social_conversation_id' => $conversation->id,
        'channel' => SocialChannel::WhatsApp->value,
        'direction' => 'inbound',
        'body' => 'Please quote me — dara@acme.test',
    ]);

    $this->actingAs($agent)
        ->get(route('leads.create', ['conversation' => $conversation->id]))
        ->assertOk()
        ->assertSee('Converting the WhatsApp chat with');

    Livewire::withQueryParams(['conversation' => $conversation->id])
        ->actingAs($agent)
        ->test(LeadForm::class)
        ->assertSet('conversationId', $conversation->id)
        ->assertSet('first_name', 'Dara')
        ->assertSet('last_name', 'Okafor')
        ->assertSet('phone', '+8801711000000')
        ->assertSet('email', 'dara@acme.test')
        ->assertSet('source', SocialChannel::WhatsApp->leadSource())
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->sole();

    expect($lead->social_conversation_id)->toBe($conversation->id)
        ->and($conversation->fresh()?->lead_id)->toBe($lead->id)
        // The attribution the automatic lead used to carry.
        ->and($lead->attribution()->source)->toBe(SocialChannel::WhatsApp->leadSource());
});

test('a chat can be converted more than once, and lists every lead it produced', function () {
    $agent = socialAgent(['social.inbox.view', 'leads.view', 'leads.create']);
    $conversation = SocialConversation::factory()->create(['participant_name' => 'Dara Okafor']);
    $first = Lead::factory()->ownedBy($agent)->create(['social_conversation_id' => $conversation->id]);
    $conversation->forceFill(['lead_id' => $first->id])->save();
    $second = Lead::factory()->ownedBy($agent)->create(['social_conversation_id' => $conversation->id]);
    $unrelated = Lead::factory()->ownedBy($agent)->create();

    Livewire::actingAs($agent)
        ->test(SocialInbox::class)
        ->call('select', $conversation->id)
        ->assertSee('Leads (2)');

    $rows = Livewire::withQueryParams(['conversation' => $conversation->id])
        ->actingAs($agent)
        ->test(LeadsIndex::class)
        ->assertSee('Leads from the Messenger chat with')
        ->instance()->dataViewQuery()->pluck('leads.id')->all();

    expect($rows)->toEqualCanonicalizing([$first->id, $second->id])
        ->and($rows)->not->toContain($unrelated->id);
});

test('the chat filter shows nothing to somebody who cannot open the inbox', function () {
    $user = socialAgent(['leads.view']);
    $conversation = SocialConversation::factory()->create();
    Lead::factory()->ownedBy($user)->create(['social_conversation_id' => $conversation->id]);

    $rows = Livewire::withQueryParams(['conversation' => $conversation->id])
        ->actingAs($user)
        ->test(LeadsIndex::class)
        ->instance()->dataViewQuery()->count();

    expect($rows)->toBe(0);
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
        ->assertSee('Convert it to a lead first');

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

test('an inbox that is empty because nothing is processing says so', function () {
    // Two causes look identical from the inbox: nobody messaged, or what they
    // sent is sitting in a queue nothing is draining. This is the second, and
    // it is the one worth a sentence.
    $source = DataSource::factory()->into('leads')->create();
    IntegrationEvent::factory()->forSource($source)->count(2)->create([
        'status' => IntegrationEventStatus::Received->value,
        'received_at' => now()->subHour(),
    ]);

    Livewire::actingAs(socialAgent())
        ->test(SocialInbox::class)
        ->assertSee('2 deliveries')
        ->assertSee('waiting to be processed');
});

test('a working inbox is not told about the queue', function () {
    Livewire::actingAs(socialAgent())
        ->test(SocialInbox::class)
        ->assertDontSee('waiting to be processed');
});
