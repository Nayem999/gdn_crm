<?php

namespace App\Domain\Meta\Webhooks\Handlers;

use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Domain\Meta\Models\MetaPage;
use App\Domain\Social\Actions\RecordInboundMessageAction;
use App\Domain\Social\DTOs\InboundSocialMessage;
use App\Domain\Social\Enums\MessageType;
use App\Domain\Social\Enums\SocialChannel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Turning a Messenger webhook into a conversation.
 *
 * All the parsing of Meta's envelope lives here, and none of it reaches
 * `RecordInboundMessageAction` — the recorder takes a value object, so the
 * untrusted document is read in one place and the code that writes records never
 * sees it. That is also what makes 12.10 a second parser rather than a second
 * inbox.
 *
 * What is skipped rather than failed, and why:
 *
 * - **A page this installation does not manage.** A webhook subscription is per
 *   app, so deliveries arrive for pages the CRM was never connected to.
 * - **An echo of our own message.** Meta sends back everything the page sends,
 *   `is_echo` and all; recording those would duplicate every reply an agent
 *   types and would re-open the thread as though the customer had written.
 * - **Delivery and read receipts.** They ride the same subscription and carry no
 *   message; 12.10 uses them to move a message's status, and until then there is
 *   nothing here for them to do.
 */
class MessengerHandler implements MetaChannelHandler
{
    public function __construct(private readonly RecordInboundMessageAction $record) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: IntegrationEventStatus, outcome: string, record?: Model|null}
     */
    public function handle(IntegrationEvent $event, array $payload): array
    {
        $recorded = 0;
        $skipped = null;
        $conversation = null;

        foreach ($this->messagingEvents($payload) as [$pageId, $messaging]) {
            $page = MetaPage::query()->where('page_id', $pageId)->first();

            if ($page === null) {
                $skipped ??= 'unknown_page';

                continue;
            }

            $message = $this->message($messaging);

            if ($message === null) {
                // An echo, a receipt, or a postback with nothing to show.
                $skipped ??= 'not_a_message';

                continue;
            }

            $inbound = $this->inbound($messaging, $message, $pageId);

            if ($inbound === null) {
                $skipped ??= 'no_sender';

                continue;
            }

            $conversation = ($this->record)($inbound, $this->owner($page));
            $recorded++;
        }

        if ($recorded === 0) {
            return [
                'status' => IntegrationEventStatus::Skipped,
                'outcome' => $skipped ?? 'no_message',
            ];
        }

        return [
            'status' => IntegrationEventStatus::Processed,
            'outcome' => 'threaded',
            // The conversation rather than the lead: this delivery was a
            // message, and the delivery log should point at what it made.
            'record' => $conversation,
        ];
    }

    /**
     * Every `messaging` entry in the envelope, paired with the page it arrived
     * at.
     *
     * Everything is checked rather than assumed: the payload is a stranger's
     * document, and an `entry` that is not a list or a `messaging` that is not
     * an object is simply not a message.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array{0: string, 1: array<string, mixed>}>
     */
    private function messagingEvents(array $payload): array
    {
        $events = [];

        foreach (is_array($payload['entry'] ?? null) ? $payload['entry'] : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $pageId = $this->text($entry, 'id');

            if ($pageId === null) {
                continue;
            }

            foreach (is_array($entry['messaging'] ?? null) ? $entry['messaging'] : [] as $messaging) {
                if (is_array($messaging)) {
                    $events[] = [$pageId, $messaging];
                }
            }
        }

        return $events;
    }

    /**
     * The message inside one messaging entry, or null when there is not one we
     * should record.
     *
     * @param  array<string, mixed>  $messaging
     * @return array<string, mixed>|null
     */
    private function message(array $messaging): ?array
    {
        $message = is_array($messaging['message'] ?? null) ? $messaging['message'] : null;

        if ($message === null) {
            return null;
        }

        // Meta echoes the page's own messages back on the same subscription.
        // Recording one would show every agent reply twice and reopen the thread
        // as though the customer had written it.
        if (($message['is_echo'] ?? false) === true) {
            return null;
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $messaging
     * @param  array<string, mixed>  $message
     */
    private function inbound(array $messaging, array $message, string $pageId): ?InboundSocialMessage
    {
        $sender = is_array($messaging['sender'] ?? null) ? $messaging['sender'] : [];
        $senderId = $this->text($sender, 'id');

        if ($senderId === null) {
            return null;
        }

        [$type, $media] = $this->attachments($message);

        $inbound = new InboundSocialMessage(
            channel: SocialChannel::Messenger,
            // The sender's page-scoped id **is** the thread: Messenger has one
            // conversation per person per page, and this is the id a reply is
            // addressed to.
            externalConversationId: $senderId,
            externalMessageId: $this->text($message, 'mid'),
            channelAccountId: $pageId,
            participantExternalId: $senderId,
            // Meta sends no profile with the webhook. The name is filled in the
            // first time anything supplies one — the inbox can ask Graph for it
            // — and until then the thread reads as "Messenger user" rather than
            // inventing something from an id.
            participantName: null,
            participantHandle: null,
            type: $type,
            body: $this->text($message, 'text'),
            media: $media,
            sentAt: $this->sentAt($messaging),
            // What a click-to-Messenger advertisement carries. Kept as it
            // arrived; 12.11 attributes the lead from it.
            referral: is_array($messaging['referral'] ?? null) ? $messaging['referral'] : null,
        );

        return $inbound->hasContent() ? $inbound : null;
    }

    /**
     * What came with the message, if anything.
     *
     * @param  array<string, mixed>  $message
     * @return array{0: MessageType, 1: array<int, array<string, mixed>>}
     */
    private function attachments(array $message): array
    {
        $attachments = is_array($message['attachments'] ?? null) ? $message['attachments'] : [];
        $media = [];
        $type = MessageType::Text;

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $attachmentType = $this->text($attachment, 'type');
            $payload = is_array($attachment['payload'] ?? null) ? $attachment['payload'] : [];

            $media[] = [
                'type' => $attachmentType,
                // Meta's URLs for attachments expire. The link is kept so the
                // thread can say what arrived; fetching the bytes onto the
                // private disk is 12.10's, where WhatsApp media makes it
                // unavoidable.
                'url' => $this->text($payload, 'url'),
            ];

            if ($type === MessageType::Text) {
                $type = MessageType::fromMeta($attachmentType);
            }
        }

        return [$type, $media];
    }

    /**
     * Who owns a lead this conversation produces.
     *
     * Whoever connected Meta. A conversation is not a delivery through a data
     * source, so there is no configured owner to read — and a lead owned by
     * somebody who can at least find it beats one owned by nobody.
     */
    private function owner(MetaPage $page): ?User
    {
        return $page->account?->connectedBy;
    }

    /**
     * @param  array<string, mixed>  $messaging
     */
    private function sentAt(array $messaging): ?Carbon
    {
        $timestamp = $messaging['timestamp'] ?? null;

        // Meta counts in milliseconds here, unlike the seconds it uses on
        // lead-ads payloads. Reading one as the other puts the message fifty
        // thousand years into the future, and the reply window with it.
        return is_numeric($timestamp)
            ? Carbon::createFromTimestampMs((int) $timestamp)
            : null;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function text(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
