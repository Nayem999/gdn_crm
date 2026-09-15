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
use App\Domain\Social\Models\SocialConversation;
use App\Domain\Social\Models\SocialMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Answering a customer.
 *
 * **The window is checked here, not only on the screen.** The inbox disables the
 * reply box when Meta's window has closed, but a disabled control is a courtesy
 * and not a boundary — a stale tab, a second browser or anything that calls this
 * directly must meet the same refusal. The refusal carries Meta's own reason so
 * that whoever reads it can find the rule rather than guess at it.
 *
 * **The message row is written before the call and updated after it.** A send
 * that fails leaves evidence: an agent who typed three paragraphs into a thread
 * that Meta then refused needs to see the attempt and the reason, not an empty
 * conversation and a toast that has already gone.
 *
 * Only Messenger sends here. WhatsApp's send is 12.10's, through the Cloud API
 * and its own templates — the shape is the same, which is why the window, the
 * refusal and the message row are all channel-agnostic already.
 */
class SendSocialMessageAction
{
    public function __construct(private readonly MetaGraphClient $client) {}

    /**
     * @throws RuntimeException when the window has closed, the page cannot be
     *                          used, or Meta refuses the message
     */
    public function __invoke(SocialConversation $conversation, string $body, User $sender): SocialMessage
    {
        $body = trim($body);

        if ($body === '') {
            throw new RuntimeException('There is nothing to send.');
        }

        $refusal = $conversation->windowRefusal();

        if ($refusal !== null) {
            throw new RuntimeException($refusal);
        }

        if ($conversation->channel() !== SocialChannel::Messenger) {
            // Honest rather than silent: WhatsApp arrives in 12.10, and a reply
            // box that accepted the message and dropped it would be worse than
            // one that says so.
            throw new RuntimeException('Replying on '.$conversation->channel()->label().' is not available yet.');
        }

        $page = $this->page($conversation);
        $message = $this->pending($conversation, $body, $sender);

        try {
            $response = $this->client->post($page->page_id.'/messages', [
                // Meta's Send API takes these as JSON strings inside a form
                // post, not as nested form fields.
                'recipient' => (string) json_encode(['id' => $conversation->external_conversation_id]),
                'message' => (string) json_encode(['text' => $body]),
                // A reply inside the window. Anything else needs a tag, which is
                // what the refusal above tells the agent to use.
                'messaging_type' => 'RESPONSE',
            ], (string) $page->access_token);
        } catch (MetaApiException $exception) {
            $message->forceFill([
                'status' => MessageStatus::Failed->value,
                // Meta's own words, truncated: this is read in a thread, and
                // their error text can carry a paragraph of documentation.
                'error' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();

            throw new RuntimeException('Meta refused the message: '.$exception->getMessage(), previous: $exception);
        }

        $this->sent($message, $response);
        $this->settle($conversation);

        return $message->refresh();
    }

    /**
     * The page this conversation arrived at, with a token that still works.
     */
    private function page(SocialConversation $conversation): MetaPage
    {
        $page = $conversation->channel_account_id === null
            ? null
            : MetaPage::query()->where('page_id', $conversation->channel_account_id)->first();

        if ($page === null) {
            throw new RuntimeException('This conversation arrived at a page that is no longer connected.');
        }

        if (! $page->isUsable()) {
            throw new RuntimeException(sprintf(
                '"%s" has no access token. Reconnect it under Settings → Meta.',
                $page->name,
            ));
        }

        return $page;
    }

    /**
     * The row that exists before Meta has been asked.
     */
    private function pending(SocialConversation $conversation, string $body, User $sender): SocialMessage
    {
        $message = new SocialMessage;

        $message->forceFill([
            'social_conversation_id' => $conversation->getKey(),
            'channel' => $conversation->getAttributeValue('channel'),
            'direction' => MessageDirection::Outbound->value,
            'type' => MessageType::Text->value,
            'body' => $body,
            'status' => MessageStatus::Pending->value,
            'sender_user_id' => $sender->getKey(),
        ])->save();

        return $message;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function sent(SocialMessage $message, array $response): void
    {
        $id = $response['message_id'] ?? null;

        $message->forceFill([
            // Meta's id for what we just sent, which is what its delivery and
            // read receipts will name in 12.10.
            'external_message_id' => is_string($id) && $id !== '' ? $id : null,
            'status' => MessageStatus::Sent->value,
            'sent_at' => Carbon::now(),
            'error' => null,
        ])->save();
    }

    /**
     * The conversation after a reply: read, and waiting on them.
     */
    private function settle(SocialConversation $conversation): void
    {
        $conversation->forceFill([
            'unread_count' => 0,
            // Deliberately **not** the window: Meta measures that from the
            // customer's last message, and a reply does not extend it. Writing
            // one here would make the inbox believe a thread is open long after
            // Meta has closed it.
            'status' => $conversation->status()->isClosed()
                ? $conversation->getAttributeValue('status')
                : ConversationStatus::Pending->value,
            'last_message_at' => Carbon::now(),
        ])->save();
    }
}
