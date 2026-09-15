<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Leads\Models\Lead;
use App\Domain\Meta\Models\MetaAccount;
use App\Domain\Meta\Models\WhatsAppBusinessAccount;
use App\Domain\Meta\Models\WhatsAppPhoneNumber;
use App\Domain\Social\Enums\SocialChannel;
use App\Domain\Social\Models\SocialConversation;
use App\Models\User;

/**
 * Task 12.10 §19 — starting a WhatsApp conversation from a record.
 */
function waSender(): WhatsAppPhoneNumber
{
    $waba = WhatsAppBusinessAccount::query()->create([
        'meta_account_id' => MetaAccount::factory()->create()->id,
        'waba_id' => '99887766',
        'name' => 'Golden Infotech WhatsApp',
        'access_token' => 'EAAWabaToken',
    ]);

    return WhatsAppPhoneNumber::query()->create([
        'whatsapp_business_account_id' => $waba->id,
        'phone_number_id' => '556677889900',
        'display_number' => '+44 117 000 0000',
        'is_default' => true,
    ]);
}

function waAgent(array $permissions = ['social.inbox.view', 'social.inbox.reply', 'leads.view', 'contacts.view']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $model) {
        $user->givePermissionTo($model);
    }

    return $user->fresh();
}

test('messaging a lead opens a conversation and hands over to the inbox', function () {
    waSender();

    $agent = waAgent();
    $lead = Lead::factory()->ownedBy($agent)->create(['mobile' => '+44 7700 900123']);

    $this->actingAs($agent)
        ->post(route('social.start.lead', $lead))
        ->assertRedirect();

    $conversation = SocialConversation::query()->firstOrFail();

    expect($conversation->channel())->toBe(SocialChannel::WhatsApp)
        // Digits only, which is what Meta wants.
        ->and($conversation->external_conversation_id)->toBe('447700900123')
        ->and($conversation->lead_id)->toBe($lead->id)
        ->and($conversation->channel_account_id)->toBe('556677889900')
        // Meta opens the window when the customer writes, not when we do — so a
        // thread started from this side can only be opened with a template.
        ->and($conversation->window_expires_at)->toBeNull()
        ->and($conversation->isWindowOpen())->toBeFalse();
});

test('an existing thread is joined rather than duplicated', function () {
    waSender();

    $agent = waAgent();
    // Mobile explicitly: WhatsApp is a mobile application, so the action prefers
    // that column, and the factory fills it with a random number otherwise.
    $contact = Contact::factory()->create([
        'mobile' => '447700900123',
        'phone' => null,
        'owner_id' => $agent->id,
    ]);

    $existing = SocialConversation::factory()->onChannel(SocialChannel::WhatsApp)->create([
        'external_conversation_id' => '447700900123',
    ]);

    $this->actingAs($agent)->post(route('social.start.contact', $contact))->assertRedirect();

    // The customer sees one thread whatever the CRM thinks; a second row here
    // would split the history in half.
    expect(SocialConversation::query()->count())->toBe(1)
        ->and($existing->fresh()?->contact_id)->toBe($contact->id);
});

test('a record with no number says so rather than opening an empty thread', function () {
    waSender();

    $agent = waAgent();
    $lead = Lead::factory()->ownedBy($agent)->create(['phone' => null, 'mobile' => null]);

    $this->actingAs($agent)
        ->post(route('social.start.lead', $lead))
        ->assertRedirect(route('leads.show', $lead))
        ->assertSessionHas('error');

    expect(SocialConversation::query()->count())->toBe(0);
});

test('with no connected number the refusal names the fix', function () {
    $agent = waAgent();
    $lead = Lead::factory()->ownedBy($agent)->create(['mobile' => '447700900123']);

    $this->actingAs($agent)->post(route('social.start.lead', $lead));

    expect(session('error'))->toContain('Connect one under Settings');
});

test('reading the inbox is not enough to start a conversation', function () {
    waSender();

    $agent = waAgent(['social.inbox.view', 'leads.view']);
    $lead = Lead::factory()->ownedBy($agent)->create(['mobile' => '447700900123']);

    // Starting a thread sends a message from the company's number, which is a
    // different act from reading one.
    $this->actingAs($agent)
        ->post(route('social.start.lead', $lead))
        ->assertForbidden();

    expect(SocialConversation::query()->count())->toBe(0);
});

test('a lead somebody cannot see cannot be messaged', function () {
    waSender();

    $lead = Lead::factory()->create(['mobile' => '447700900123']);

    $this->actingAs(waAgent())
        ->post(route('social.start.lead', $lead))
        ->assertForbidden();
});
