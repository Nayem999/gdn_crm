<?php

namespace App\Domain\Social\Actions;

use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Graph\MetaGraphClient;
use App\Domain\Meta\Models\WhatsAppPhoneNumber;
use App\Domain\Social\Enums\ConversationStatus;
use App\Domain\Social\Enums\MessageDirection;
use App\Domain\Social\Enums\MessageStatus;
use App\Domain\Social\Enums\MessageType;
use App\Domain\Social\Enums\SocialChannel;
use App\Domain\Social\Models\SocialConversation;
use App\Domain\Social\Models\SocialMessage;
use App\Domain\Social\Models\WhatsAppTemplate;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The one thing that may be sent after Meta's window has closed.
 *
 * A template is not a message with a flag on it. It is text Meta has already
 * read and approved, sent by **name** with its values as separate parameters —
 * Meta does the substitution at its end, against the copy it approved. Sending
 * the rendered string instead would be sending a free-form message, which is
 * exactly what the closed window forbids and what Meta refuses.
 *
 * Three refusals happen here, before Meta is asked, because Meta's own answers
 * to them are unhelpful:
 *
 * - **A template that is not approved.** Meta pauses templates when customers
 *   report them and does it without telling anybody, so approval is read from
 *   the stored status rather than assumed from the row existing. Its error code
 *   for this is a number.
 * - **The wrong number of values.** Meta answers `132000`, which tells an agent
 *   nothing; the stored variable list says exactly how many were wanted.
 * - **A template from another business account.** Approval belongs to the WABA
 *   that owns it, and sending one number's template from another's fails in a
 *   way that reads like a permissions problem.
 *
 * The window is deliberately **not** checked. A template is allowed whether or
 * not it is open — that is what it is for — and refusing one inside the window
 * would make the feature useless in the only situation where somebody is
 * definitely paying attention.
 */
class SendWhatsAppTemplateAction
{
    public function __construct(
        private readonly MetaGraphClient $client,
        private readonly SendSocialMessageAction $send,
    ) {}

    /**
     * @param  array<int, string>  $values  In the order the template's
     *                                      placeholders are numbered.
     *
     * @throws RuntimeException when the template cannot be used or Meta refuses it
     */
    public function __invoke(
        SocialConversation $conversation,
        WhatsAppTemplate $template,
        array $values,
        User $sender,
    ): SocialMessage {
        if ($conversation->channel() !== SocialChannel::WhatsApp) {
            throw new RuntimeException('Templates are a WhatsApp thing; '.$conversation->channel()->label().' uses message tags.');
        }

        if (! $template->isSendable()) {
            // Meta's reason for this is a number. The status carries words.
            throw new RuntimeException($template->status()->refusal() ?? 'That template cannot be sent.');
        }

        $invalid = $template->validationError($values);

        if ($invalid !== null) {
            throw new RuntimeException($invalid);
        }

        $this->guardOwnership($conversation, $template);

        [$path, $payload, $token] = $this->send->whatsAppTransport($conversation, [
            'type' => 'template',
            'template' => [
                'name' => $template->name,
                'language' => ['code' => $template->language],
                // Omitted entirely when the template takes none: Meta refuses a
                // components array carrying an empty parameter list.
                ...($values === [] ? [] : ['components' => [[
                    'type' => 'body',
                    'parameters' => array_map(
                        fn (string $value): array => ['type' => 'text', 'text' => $value],
                        array_values($values),
                    ),
                ]]]),
            ],
        ]);

        $message = $this->pending($conversation, $template, $values, $sender);

        try {
            $response = $this->client->post($path, $payload, $token);
        } catch (MetaApiException $exception) {
            $message->forceFill([
                'status' => MessageStatus::Failed->value,
                'error' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();

            throw new RuntimeException('Meta refused the template: '.$exception->getMessage(), previous: $exception);
        }

        $id = is_array($response['messages'][0] ?? null) ? ($response['messages'][0]['id'] ?? null) : null;

        $message->forceFill([
            'external_message_id' => is_string($id) && $id !== '' ? $id : null,
            'status' => MessageStatus::Sent->value,
            'sent_at' => Carbon::now(),
            'error' => null,
        ])->save();

        $conversation->forceFill([
            'unread_count' => 0,
            // A template does not open a window — only the customer replying
            // does. The conversation is waiting on them either way.
            'status' => $conversation->status()->isClosed()
                ? $conversation->getAttributeValue('status')
                : ConversationStatus::Pending->value,
            'last_message_at' => Carbon::now(),
        ])->save();

        return $message->refresh();
    }

    /**
     * A template belongs to the business account that got it approved.
     */
    private function guardOwnership(SocialConversation $conversation, WhatsAppTemplate $template): void
    {
        if ($template->waba_id === null) {
            return;
        }

        $waba = $conversation->channel_account_id === null
            ? null
            : WhatsAppPhoneNumber::query()
                ->with('businessAccount')
                ->where('phone_number_id', $conversation->channel_account_id)
                ->first()?->businessAccount?->waba_id;

        if ($waba !== null && $waba !== $template->waba_id) {
            throw new RuntimeException(sprintf(
                '"%s" was approved for a different WhatsApp business account.',
                $template->name,
            ));
        }
    }

    /**
     * @param  array<int, string>  $values
     */
    private function pending(
        SocialConversation $conversation,
        WhatsAppTemplate $template,
        array $values,
        User $sender,
    ): SocialMessage {
        $message = new SocialMessage;

        $message->forceFill([
            'social_conversation_id' => $conversation->getKey(),
            'channel' => $conversation->getAttributeValue('channel'),
            'direction' => MessageDirection::Outbound->value,
            'type' => MessageType::Template->value,
            // The rendered text, for the thread to show. What went to Meta was
            // the name and the values; this is what the customer will read.
            'body' => $template->preview($values),
            'template_name' => $template->name,
            'status' => MessageStatus::Pending->value,
            'sender_user_id' => $sender->getKey(),
        ])->save();

        return $message;
    }
}
