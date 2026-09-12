<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Activities\Models\Activity;
use App\Domain\Chat\Models\ChatConversation;
use App\Domain\Chat\Models\ChatMessage;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Mail\Enums\EmailStatus;
use App\Domain\Mail\Models\EmailMessage;
use App\Domain\Mail\Models\InboundMessage;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Timeline\Communications\CommunicationChannel;
use App\Domain\Timeline\Enums\TimelineEntryKind;
use App\Domain\Timeline\TimelineBuilder;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The timeline, narrowed to the strands under test.
 *
 * Creating any record writes an audit entry, so an unfiltered timeline is never
 * empty and these assertions would be about the history strand rather than the
 * communication one.
 */
function timelineFor($subject, ?User $viewer = null, ?array $kinds = null)
{
    return app(TimelineBuilder::class)->for($subject, 20, $kinds ?? [TimelineEntryKind::Communication], $viewer);
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-12 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('merges every channel into one chronological list', function () {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(PermissionResolver::models(['activities.view']));
    $viewer = $viewer->fresh();

    $contact = Contact::factory()->create([
        'email' => 'buyer@example.com',
        'phone' => '+8801811111111',
        'owner_id' => $viewer->id,
    ]);

    // Four channels, four moments, deliberately created out of order.
    EmailMessage::factory()->create([
        'to_email' => 'buyer@example.com',
        'subject' => 'Your quote',
        'sent_at' => Carbon::parse('2026-09-10 09:00:00'),
    ]);

    $conversation = ChatConversation::factory()->create([
        'visitor_email' => 'buyer@example.com',
        'last_message_at' => Carbon::parse('2026-09-11 09:00:00'),
    ]);

    ChatMessage::query()->create([
        'chat_conversation_id' => $conversation->id,
        'author' => 'visitor',
        'body' => 'Is the quote still valid?',
        'sent_at' => Carbon::parse('2026-09-11 09:00:00'),
    ]);

    NotificationLog::query()->create([
        'event' => 'quote.sent',
        'channel' => NotificationChannel::Sms->value,
        'recipient_type' => 'customer',
        'recipient' => '+8801811111111',
        'status' => 'sent',
        'subject' => 'Quote sent',
        'attempts' => 1,
        'sent_at' => Carbon::parse('2026-09-12 09:00:00'),
        'created_at' => Carbon::parse('2026-09-12 09:00:00'),
    ]);

    Activity::factory()->create([
        'type' => 'call',
        'subject' => 'Follow-up call',
        'due_at' => Carbon::parse('2026-09-13 09:00:00'),
        'related_type' => $contact->getMorphClass(),
        'related_id' => $contact->id,
        'owner_id' => $viewer->id,
    ]);

    InboundMessage::factory()->create([
        'from_email' => 'buyer@example.com',
        'subject' => 'Re: Your quote',
        'body' => 'Looks good.',
        'received_at' => Carbon::parse('2026-09-14 09:00:00'),
    ]);

    $page = timelineFor($contact, $viewer, [TimelineEntryKind::Communication, TimelineEntryKind::Activity]);

    $titles = array_map(fn ($entry) => $entry->title, $page->entries);

    // Newest first, across four different tables and four different shapes.
    expect($titles[0])->toContain('Re: Your quote')
        ->and($titles[1])->toBe('Call: Follow-up call')
        ->and($titles[2])->toContain('SMS sent')
        ->and($titles[3])->toContain('Chat received')
        ->and($titles[4])->toContain('Email sent');
});

it('finds a message linked to the record even when the address is somebody else', function () {
    $contact = Contact::factory()->create(['email' => 'office@example.com']);

    EmailMessage::factory()->create([
        'to_email' => 'assistant@example.com',
        'subject' => 'Sent to their assistant',
        'related_type' => $contact->getMorphClass(),
        'related_id' => $contact->id,
        'sent_at' => now(),
    ]);

    expect(timelineFor($contact)->entries)->toHaveCount(1);
});

it('finds a message sent to the record address before anything linked it', function () {
    $contact = Contact::factory()->create(['email' => 'buyer@example.com']);

    EmailMessage::factory()->create([
        'to_email' => 'buyer@example.com',
        'subject' => 'Sent before the link existed',
        'sent_at' => now(),
    ]);

    expect(timelineFor($contact)->entries)->toHaveCount(1);
});

it('does not put somebody else messages on a record', function () {
    $contact = Contact::factory()->create(['email' => 'buyer@example.com', 'phone' => '+8801811111111']);

    EmailMessage::factory()->create(['to_email' => 'stranger@example.com', 'sent_at' => now()]);
    InboundMessage::factory()->create(['from_email' => 'stranger@example.com', 'received_at' => now()]);
    ChatConversation::factory()->create(['visitor_email' => 'stranger@example.com', 'last_message_at' => now()]);

    expect(timelineFor($contact)->entries)->toBe([]);
});

it('shows a whole chat as one entry rather than one per line', function () {
    $contact = Contact::factory()->create(['email' => 'buyer@example.com']);

    $conversation = ChatConversation::factory()->create([
        'visitor_email' => 'buyer@example.com',
        'last_message_at' => now(),
    ]);

    foreach (['Hello', 'Are you there?', 'Never mind'] as $index => $body) {
        ChatMessage::query()->create([
            'chat_conversation_id' => $conversation->id,
            'author' => 'visitor',
            'body' => $body,
            'sent_at' => now()->addMinutes($index),
        ]);
    }

    $entries = timelineFor($contact)->entries;

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->body)->toContain('Hello')
        ->and($entries[0]->body)->toContain('Never mind');
});

it('can be filtered down to messages alone', function () {
    $contact = Contact::factory()->create(['email' => 'buyer@example.com']);

    EmailMessage::factory()->create(['to_email' => 'buyer@example.com', 'sent_at' => now()]);

    expect(timelineFor($contact, null, [TimelineEntryKind::Communication])->entries)->toHaveCount(1)
        ->and(timelineFor($contact, null, [TimelineEntryKind::Note])->entries)->toBe([]);
});

it('says which way each message went and what became of it', function () {
    $contact = Contact::factory()->create(['email' => 'buyer@example.com']);

    EmailMessage::factory()->create([
        'to_email' => 'buyer@example.com',
        'status' => EmailStatus::Bounced,
        'sent_at' => now(),
    ]);

    $entry = timelineFor($contact)->entries[0];

    expect($entry->communication?->channel)->toBe(CommunicationChannel::Email)
        ->and($entry->communication?->outbound)->toBeTrue()
        ->and($entry->communication?->status)->toBe('Bounced');
});

it('matches a phone number exactly, and does not pretend otherwise', function () {
    // Written down because it is a real limitation: normalising numbers needs a
    // library and a country, and a loose match would put somebody else's
    // messages on a customer's timeline.
    $contact = Contact::factory()->create(['phone' => '+8801811111111']);

    NotificationLog::query()->create([
        'event' => 'quote.sent',
        'channel' => NotificationChannel::Sms->value,
        'recipient_type' => 'customer',
        'recipient' => '01811 111111',
        'status' => 'sent',
        'attempts' => 1,
        'sent_at' => now(),
    ]);

    expect(timelineFor($contact)->entries)->toBe([]);
});
