<?php

namespace App\Domain\Social\Actions;

use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Graph\MetaGraphClient;
use App\Domain\Meta\Models\MetaPage;
use App\Domain\Social\Enums\ConversationStatus;
use App\Domain\Social\Enums\MessageDirection;
use App\Domain\Social\Enums\MessageStatus;
use App\Domain\Social\Enums\MessageType;
use App\Domain\Social\Enums\SocialChannel;
use App\Domain\Social\MessagingWindow;
use App\Domain\Social\Models\SocialConversation;
use App\Domain\Social\Models\SocialMessage;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Reading the conversations a Page already had, before this CRM was connected.
 *
 * **This exists for Messenger and cannot exist for WhatsApp.** A Page's
 * conversations are readable from the Graph API with their messages; the
 * WhatsApp Cloud API has no such endpoint at all — asking for one answers
 * "(#100) Tried accessing nonexisting field". So a WhatsApp thread begins the
 * moment a message is delivered to us and there is no history to fetch, while a
 * Messenger thread can be brought across in full. Saying that plainly beats a
 * button that appears to work on both and silently returns nothing on one.
 *
 * **Imported messages are ordinary messages.** They thread on the same id a
 * webhook threads on — the customer's page-scoped id, not Meta's `t_`
 * conversation id — and they are idempotent on the same message id. An import
 * followed by a live delivery therefore continues one thread rather than
 * starting a second, which is the whole reason not to write a parallel
 * importer.
 *
 * **It does not create leads unless asked.** A stranger writing today is an
 * enquiry worth a lead; two years of history is not two years of new enquiries,
 * and a button that puts 124 of them into somebody's pipeline is harder to undo
 * than to skip.
 *
 * **The reply window is taken from the customer's last message**, exactly as a
 * webhook would set it, so a conversation imported today does not appear to
 * grant seven fresh days on a message sent three weeks ago.
 */
class ImportMessengerHistoryAction
{
    /**
     * How much is read. Meta pages these, and a Page with years of history
     * would otherwise pull down everything on the first press of a button.
     */
    private const CONVERSATION_PAGES = 5;

    private const MESSAGES_PER_CONVERSATION = 50;

    public function __construct(private readonly MetaGraphClient $client) {}

    /**
     * @param  User|null  $owner  Who leads belong to **if** leads are wanted.
     *                            Null — the default — imports the conversations
     *                            and creates nothing: a live message from a
     *                            stranger is an enquiry worth a lead, but a
     *                            bulk import of years of history is not 124 new
     *                            enquiries, and filling somebody's pipeline
     *                            with them on the press of one button is not a
     *                            decision this action should take for them. The
     *                            inbox's own "create lead" button is there for
     *                            the ones that matter.
     * @return array{conversations: int, messages: int, skipped: int}
     *
     * @throws MetaApiException
     */
    public function __invoke(MetaPage $page, ?User $owner = null): array
    {
        $token = $page->access_token;

        if (! is_string($token) || $token === '') {
            throw new MetaApiException(
                'That Page has no access token stored. Add one under Settings → Meta connection before importing.'
            );
        }

        $counts = ['conversations' => 0, 'messages' => 0, 'skipped' => 0];

        foreach ($this->client->paginate($page->page_id.'/conversations', [
            'fields' => 'id,updated_time,participants,messages.limit('.self::MESSAGES_PER_CONVERSATION.'){id,message,from,created_time}',
            'limit' => 25,
        ], $token, self::CONVERSATION_PAGES) as $row) {
            $customer = $this->customer($row, $page->page_id);

            if ($customer === null) {
                // A conversation with nobody but the Page in it, which Meta
                // does produce for deleted accounts. Nothing to thread onto.
                $counts['skipped']++;

                continue;
            }

            $written = $this->conversation($page, $customer, $row, $owner);

            $counts['conversations'] += $written === 0 ? 0 : 1;
            $counts['messages'] += $written;
        }

        return $counts;
    }

    /**
     * The participant who is not us.
     *
     * @param  array<string, mixed>  $row
     * @return array{id: string, name: string|null}|null
     */
    private function customer(array $row, string $pageId): ?array
    {
        $participants = is_array($row['participants']['data'] ?? null) ? $row['participants']['data'] : [];

        foreach ($participants as $participant) {
            $id = is_array($participant) && isset($participant['id']) ? (string) $participant['id'] : null;

            if ($id === null || $id === $pageId) {
                continue;
            }

            return [
                'id' => $id,
                'name' => is_string($participant['name'] ?? null) ? $participant['name'] : null,
            ];
        }

        return null;
    }

    /**
     * Write one conversation's messages, and return how many were new.
     *
     * @param  array{id: string, name: string|null}  $customer
     * @param  array<string, mixed>  $row
     */
    private function conversation(MetaPage $page, array $customer, array $row, ?User $owner): int
    {
        $conversation = SocialConversation::query()->firstOrNew([
            'channel' => SocialChannel::Messenger->value,
            // The customer's id, which is what a live delivery threads on.
            // Meta's own `t_` conversation id would make a second thread for
            // everybody the moment they wrote again.
            'external_conversation_id' => $customer['id'],
        ]);

        foreach (['participant_external_id' => $customer['id'], 'participant_name' => $customer['name'], 'channel_account_id' => $page->page_id] as $column => $value) {
            if ($value !== null && blank($conversation->getAttributeValue($column))) {
                $conversation->setAttribute($column, $value);
            }
        }

        if (! $conversation->exists) {
            $conversation->setAttribute('status', ConversationStatus::Open->value);
        }

        $isNew = ! $conversation->exists;

        $conversation->save();

        // Oldest first, so a thread reads in the order it happened rather than
        // the order Meta returns it.
        $messages = is_array($row['messages']['data'] ?? null) ? array_reverse($row['messages']['data']) : [];

        $written = 0;
        $lastInbound = null;
        $lastAt = null;

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $id = is_string($message['id'] ?? null) ? $message['id'] : null;
            $body = is_string($message['message'] ?? null) ? $message['message'] : null;
            $sentAt = is_string($message['created_time'] ?? null) ? Carbon::parse($message['created_time']) : null;

            if ($id === null || $sentAt === null) {
                continue;
            }

            $inbound = ((string) ($message['from']['id'] ?? '')) !== $page->page_id;

            $lastAt = $lastAt === null || $sentAt->gt($lastAt) ? $sentAt : $lastAt;

            if ($inbound && ($lastInbound === null || $sentAt->gt($lastInbound))) {
                $lastInbound = $sentAt;
            }

            $exists = SocialMessage::query()
                ->where('channel', SocialChannel::Messenger->value)
                ->where('external_message_id', $id)
                ->exists();

            if ($exists) {
                continue;
            }

            // An attachment-only message has no text, and Meta does not return
            // the attachment on this edge. Recorded with a note rather than
            // dropped: a gap in a thread reads as a bug.
            (new SocialMessage)->forceFill([
                'social_conversation_id' => $conversation->getKey(),
                'channel' => SocialChannel::Messenger->value,
                'external_message_id' => $id,
                'direction' => $inbound ? MessageDirection::Inbound->value : MessageDirection::Outbound->value,
                'type' => MessageType::Text->value,
                'body' => $body ?? '[attachment]',
                'status' => $inbound ? MessageStatus::Received->value : MessageStatus::Sent->value,
                'sent_at' => $sentAt,
            ])->save();

            $written++;
        }

        if ($written === 0) {
            // Nothing new. A thread this import created and then put nothing
            // in is an empty row in somebody's inbox, so it goes again.
            if ($isNew) {
                $conversation->delete();
            }

            return 0;
        }

        $conversation->forceFill(array_filter([
            'last_message_at' => $lastAt,
            // From the customer's last message, as a webhook would have set it.
            // Most imported threads are therefore already outside their window,
            // which is the truth: Meta will refuse a free-form reply to them.
            'window_expires_at' => $lastInbound === null
                ? null
                : MessagingWindow::expiresAt(SocialChannel::Messenger, $lastInbound),
        ], fn (mixed $value): bool => $value !== null))->save();

        if ($owner !== null && ! $conversation->refresh()->isLinked()) {
            // The same action a live message uses, so an imported conversation
            // and a delivered one produce the same lead.
            app(CreateLeadFromConversationAction::class)($conversation, $owner, $lastAt);
        }

        return $written;
    }
}
