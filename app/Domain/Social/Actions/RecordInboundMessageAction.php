<?php

namespace App\Domain\Social\Actions;

use App\Domain\Social\DTOs\InboundSocialMessage;
use App\Domain\Social\Enums\ConversationStatus;
use App\Domain\Social\Enums\MessageDirection;
use App\Domain\Social\Enums\MessageStatus;
use App\Domain\Social\MessagingWindow;
use App\Domain\Social\Models\SocialConversation;
use App\Domain\Social\Models\SocialMessage;
use App\Jobs\FetchWhatsAppMedia;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A message arriving from a customer, threaded and answered for.
 *
 * Four things happen here and each has a rule behind it:
 *
 * **Threading is on `(channel, external_conversation_id)`**, which is unique at
 * the database. Meta retries its webhooks and two queue workers can race the
 * same delivery, so "which conversation is this" has to be a fact rather than a
 * hope — a second thread for one customer is the failure everybody notices,
 * because the reply goes into the wrong half of it.
 *
 * **The same message twice is one message.** Idempotency is on Meta's own id,
 * for the same reason 12.5 keys deliveries by it: a retry carries the same mid,
 * and recording it again would show the customer's question twice and count it
 * as unread twice.
 *
 * **The window is opened by the customer, never by us.** Meta measures it from
 * their last message, so it is set here and nowhere else — a reply that extended
 * it would make the inbox believe a thread is open long after Meta has closed
 * it, which is how a number's quality rating gets damaged.
 *
 * **An unknown sender becomes a lead.** Not on a "hello" alone — on the first
 * message from somebody this installation cannot already name, because unlike an
 * anonymous website visitor a Messenger or WhatsApp sender **is** durably
 * reachable: the thread itself is the address, and an agent can answer it
 * tomorrow. That is what makes it a lead worth having rather than a row somebody
 * deletes. A conversation already tied to a lead or a contact never makes a
 * second one, however many messages arrive.
 */
class RecordInboundMessageAction
{
    public function __construct(
        private readonly CreateLeadFromConversationAction $createLeadFromConversation,
        private readonly MatchConversationToRecordAction $match,
    ) {}

    /**
     * @param  User|null  $owner  Who a created lead belongs to. A conversation
     *                            with nobody to own what it produces records the
     *                            message and creates nothing — losing a message
     *                            because the integration is half configured
     *                            would be the worse failure.
     */
    public function __invoke(InboundSocialMessage $inbound, ?User $owner = null): SocialConversation
    {
        return DB::transaction(function () use ($inbound, $owner): SocialConversation {
            $conversation = $this->conversation($inbound);
            $message = $this->record($conversation, $inbound);

            // Already had it. Nothing about the conversation moves — not the
            // unread count, not the window — because nothing actually arrived.
            if ($message === null) {
                return $conversation;
            }

            $this->touch($conversation, $inbound);
            $this->identify($conversation, $inbound, $owner);

            return $conversation->refresh();
        });
    }

    /**
     * The thread this belongs to, created if it is new.
     */
    private function conversation(InboundSocialMessage $inbound): SocialConversation
    {
        $conversation = SocialConversation::query()->firstOrNew([
            'channel' => $inbound->channel->value,
            'external_conversation_id' => $inbound->externalConversationId,
        ]);

        // Details arrive late and change: somebody sets a display name after
        // three messages, and Meta only sends the profile when it has been
        // asked for. Each field is filled the first time it is offered and never
        // blanked afterwards — and never overwritten, because a name captured in
        // March is what that conversation was actually held with.
        foreach (['participant_external_id', 'participant_name', 'participant_handle', 'channel_account_id'] as $column) {
            $value = match ($column) {
                'participant_external_id' => $inbound->participantExternalId,
                'participant_name' => $inbound->participantName,
                'participant_handle' => $inbound->participantHandle,
                default => $inbound->channelAccountId,
            };

            if ($value !== null && $value !== '' && blank($conversation->getAttributeValue($column))) {
                $conversation->setAttribute($column, $value);
            }
        }

        if (! $conversation->exists) {
            $conversation->setAttribute('status', ConversationStatus::Open->value);
        }

        $conversation->save();

        return $conversation;
    }

    /**
     * Write the message down, or nothing if we already have it.
     */
    private function record(SocialConversation $conversation, InboundSocialMessage $inbound): ?SocialMessage
    {
        if (! $inbound->hasContent()) {
            // A receipt rather than a message. Recording it would put an empty
            // bubble in the thread.
            return null;
        }

        if ($inbound->externalMessageId !== null) {
            $existing = SocialMessage::query()
                ->where('channel', $inbound->channel->value)
                ->where('external_message_id', $inbound->externalMessageId)
                ->first();

            if ($existing !== null) {
                return null;
            }
        }

        $message = new SocialMessage;

        $message->forceFill([
            'social_conversation_id' => $conversation->getKey(),
            'channel' => $inbound->channel->value,
            'external_message_id' => $inbound->externalMessageId,
            'direction' => MessageDirection::Inbound->value,
            'type' => $inbound->type->value,
            // Somebody else's text, stored as it arrived and rendered escaped.
            'body' => $inbound->body,
            'attachments' => $inbound->media === [] ? null : $inbound->media,
            // An inbound message has no delivery story: it is here, which is the
            // whole of what is known about it.
            'status' => MessageStatus::Received->value,
            'sent_at' => $inbound->sentAt(),
        ])->save();

        // WhatsApp sends a media id rather than the file, and fetching it takes
        // two calls against a URL that expires in minutes. Off the webhook, so a
        // customer's large video cannot make the delivery time out — Meta would
        // retry it, and a retried delivery is a duplicated message.
        //
        // afterCommit, because this runs inside a transaction: a job that
        // started before the commit would look for a row that is not there yet.
        if ($this->hasFetchableMedia($inbound)) {
            FetchWhatsAppMedia::dispatch((int) $message->getKey())->afterCommit();
        }

        return $message;
    }

    /**
     * Whether this message carries an attachment we can go and get.
     *
     * A media **id** rather than a URL is the tell: that is WhatsApp's shape,
     * and it is the one that needs a token and two calls. Messenger's
     * attachments arrive as links, which the thread shows without fetching.
     */
    private function hasFetchableMedia(InboundSocialMessage $inbound): bool
    {
        foreach ($inbound->media as $attachment) {
            if (($attachment['media_id'] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Move the conversation on, now that something has genuinely arrived.
     */
    private function touch(SocialConversation $conversation, InboundSocialMessage $inbound): void
    {
        $sentAt = $inbound->sentAt();

        $conversation->forceFill([
            'last_message_at' => $sentAt,
            // Meta's clock, from their message. See the class comment.
            'window_expires_at' => MessagingWindow::expiresAt($inbound->channel, $sentAt),
            'unread_count' => $conversation->unread_count + 1,
            // A customer who writes again reopens the thread. The alternative is
            // a message nobody sees because it landed in one somebody had ticked
            // off.
            'status' => $conversation->status()->isClosed()
                ? ConversationStatus::Open->value
                : $conversation->getAttributeValue('status'),
        ])->save();
    }

    /**
     * Give the conversation a lead, when it has nothing yet.
     */
    private function identify(SocialConversation $conversation, InboundSocialMessage $inbound, ?User $owner): void
    {
        if ($conversation->isLinked() || $owner === null) {
            return;
        }

        // Somebody the CRM already knows. A WhatsApp thread is a telephone
        // number, so a customer of ten years writing for the first time on
        // WhatsApp joins their own record rather than becoming a stranger.
        if (($this->match)($conversation)) {
            return;
        }

        // The same action the inbox panel's "create lead" button calls, so an
        // automatic lead and a hand-made one are the same lead.
        ($this->createLeadFromConversation)($conversation, $owner, $inbound->sentAt());
    }
}
