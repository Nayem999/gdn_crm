<?php

namespace App\Domain\Social\Actions;

use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Graph\MetaGraphClient;
use App\Domain\Meta\Models\MetaPage;
use App\Domain\Meta\Models\WhatsAppPhoneNumber;
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
 * Both channels send here, and only `transport()` knows the difference: a page
 * id and form fields for Messenger, a phone number id and JSON for the Cloud
 * API. Everything around it — the window, the pending row, the failure record,
 * the conversation settling afterwards — is identical, which is the whole
 * argument for one inbox.
 *
 * Sending a **template** outside the window is `SendWhatsAppTemplateAction`. It
 * is a different act rather than a flag on this one: it is allowed precisely
 * when this is not, it needs approval and variables rather than free text, and
 * conflating them is how a free-form message gets attempted against a closed
 * window.
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

        // Credentials first, and before any row is written: a conversation
        // whose page or number is no longer connected has nothing to send with,
        // and a pending message for a send that was never attempted would sit
        // in the thread looking like a delivery in progress.
        [$path, $payload, $token] = $this->transport($conversation, $body);

        $message = $this->pending($conversation, $body, $sender);

        try {
            $response = $this->client->post($path, $payload, $token);
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
     * Where this channel's messages go, what they look like, and what signs
     * them.
     *
     * The only part of sending that differs per channel, which is why it is one
     * method and not two actions: everything around it — the window, the pending
     * row, the failure record, the conversation settling — is identical.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: string}
     */
    private function transport(SocialConversation $conversation, string $body): array
    {
        return match ($conversation->channel()) {
            SocialChannel::Messenger => $this->messengerTransport($conversation, $body),
            SocialChannel::WhatsApp => $this->whatsAppTransport($conversation, [
                'type' => 'text',
                // `preview_url` off: a link in a reply should not silently pull
                // a preview card from somebody else's site into the thread.
                'text' => ['body' => $body, 'preview_url' => false],
            ]),
        };
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: string}
     */
    private function messengerTransport(SocialConversation $conversation, string $body): array
    {
        $page = $this->page($conversation);

        return [
            $page->page_id.'/messages',
            [
                // Meta's Send API takes these as JSON strings inside a form
                // post, not as nested form fields.
                'recipient' => (string) json_encode(['id' => $conversation->external_conversation_id]),
                'message' => (string) json_encode(['text' => $body]),
                // A reply inside the window. Anything else needs a tag, which is
                // what the window refusal tells the agent to use.
                'messaging_type' => 'RESPONSE',
            ],
            (string) $page->access_token,
        ];
    }

    /**
     * The Cloud API's shape, which is JSON rather than Messenger's form fields.
     *
     * Public so the template send shares it: a template and a free-form message
     * differ only in the `message` object, and two copies of the credential
     * lookup would be two places to get the token wrong.
     *
     * @param  array<string, mixed>  $message
     * @return array{0: string, 1: array<string, mixed>, 2: string}
     */
    public function whatsAppTransport(SocialConversation $conversation, array $message): array
    {
        $number = $this->number($conversation);
        $token = $number->businessAccount?->access_token;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException(sprintf(
                'The WhatsApp account behind %s has no access token. Reconnect it under Settings → Meta.',
                $number->display_number,
            ));
        }

        return [
            $number->phone_number_id.'/messages',
            [
                'messaging_product' => 'whatsapp',
                // The customer's number, as Meta wants it: digits only.
                'to' => ltrim($conversation->external_conversation_id, '+'),
                ...$message,
            ],
            $token,
        ];
    }

    /**
     * The number this conversation arrived at.
     */
    private function number(SocialConversation $conversation): WhatsAppPhoneNumber
    {
        $number = $conversation->channel_account_id === null
            ? null
            : WhatsAppPhoneNumber::query()
                ->with('businessAccount')
                ->where('phone_number_id', $conversation->channel_account_id)
                ->first();

        if ($number === null) {
            throw new RuntimeException('This conversation arrived at a WhatsApp number that is no longer connected.');
        }

        return $number;
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
        // Messenger answers with `message_id`; the Cloud API answers with a
        // `messages` array. Both are the id Meta's delivery receipts will name.
        $id = $response['message_id']
            ?? (is_array($response['messages'][0] ?? null) ? ($response['messages'][0]['id'] ?? null) : null);

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
