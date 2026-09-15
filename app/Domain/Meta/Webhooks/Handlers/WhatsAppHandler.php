<?php

namespace App\Domain\Meta\Webhooks\Handlers;

use App\Domain\Ingestion\Enums\IntegrationEventStatus;
use App\Domain\Ingestion\Models\IntegrationEvent;
use App\Domain\Meta\Models\WhatsAppPhoneNumber;
use App\Domain\Social\Actions\RecordDeliveryReceiptAction;
use App\Domain\Social\Actions\RecordInboundMessageAction;
use App\Domain\Social\DTOs\InboundSocialMessage;
use App\Domain\Social\Enums\MessageType;
use App\Domain\Social\Enums\SocialChannel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Turning a WhatsApp Cloud API webhook into conversations and receipts.
 *
 * The same shape as `MessengerHandler` and deliberately so: parse here, hand a
 * value object to the recorder, and the recorder never sees a webhook. What
 * differs is entirely in this file, and it is worth listing because every item
 * is a trap:
 *
 * - **The thread is the customer's telephone number.** WhatsApp has no
 *   page-scoped id; `wa_id` is the number, and it is the same number whichever
 *   of our numbers they wrote to.
 * - **Timestamps are in seconds**, where Messenger's are in milliseconds.
 *   Reading one as the other puts a message fifty thousand years out, and the
 *   reply window with it.
 * - **The profile name arrives**, which Messenger's webhook does not give. It
 *   comes in a `contacts` array beside the messages rather than on the message,
 *   so the two have to be matched on the number.
 * - **Delivery receipts share the subscription** with messages. They are not
 *   messages and must never be threaded as one; they move an outbound message's
 *   status instead.
 */
class WhatsAppHandler implements MetaChannelHandler
{
    public function __construct(
        private readonly RecordInboundMessageAction $record,
        private readonly RecordDeliveryReceiptAction $receipts,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: IntegrationEventStatus, outcome: string, record?: Model|null}
     */
    public function handle(IntegrationEvent $event, array $payload): array
    {
        $threaded = 0;
        $receipted = 0;
        $skipped = null;
        $conversation = null;

        foreach ($this->changes($payload) as $value) {
            $number = $this->number($value);

            if ($number === null) {
                $skipped ??= 'unknown_number';

                continue;
            }

            $names = $this->profileNames($value);

            foreach ($this->messages($value) as $message) {
                $inbound = $this->inbound($message, $names, $number->phone_number_id);

                if ($inbound === null) {
                    $skipped ??= 'not_a_message';

                    continue;
                }

                $conversation = ($this->record)($inbound, $this->owner($number));
                $threaded++;
            }

            // Receipts after messages, deliberately: a status for a message that
            // arrived in the same delivery has something to land on.
            $receipted += $this->statuses($value);
        }

        if ($threaded > 0) {
            return [
                'status' => IntegrationEventStatus::Processed,
                'outcome' => 'threaded',
                'record' => $conversation,
            ];
        }

        if ($receipted > 0) {
            return ['status' => IntegrationEventStatus::Processed, 'outcome' => 'receipted'];
        }

        return ['status' => IntegrationEventStatus::Skipped, 'outcome' => $skipped ?? 'no_message'];
    }

    /**
     * The `value` object of every `messages` change in the envelope.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function changes(array $payload): array
    {
        $values = [];

        foreach (is_array($payload['entry'] ?? null) ? $payload['entry'] : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach (is_array($entry['changes'] ?? null) ? $entry['changes'] : [] as $change) {
                if (! is_array($change)) {
                    continue;
                }

                $value = is_array($change['value'] ?? null) ? $change['value'] : null;

                if ($value !== null) {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * Which of our numbers this arrived at.
     *
     * @param  array<string, mixed>  $value
     */
    private function number(array $value): ?WhatsAppPhoneNumber
    {
        $metadata = is_array($value['metadata'] ?? null) ? $value['metadata'] : [];
        $phoneNumberId = $this->text($metadata, 'phone_number_id');

        if ($phoneNumberId === null) {
            return null;
        }

        return WhatsAppPhoneNumber::query()->where('phone_number_id', $phoneNumberId)->first();
    }

    /**
     * Customer numbers mapped to the names on their WhatsApp profiles.
     *
     * @param  array<string, mixed>  $value
     * @return array<string, string>
     */
    private function profileNames(array $value): array
    {
        $names = [];

        foreach (is_array($value['contacts'] ?? null) ? $value['contacts'] : [] as $contact) {
            if (! is_array($contact)) {
                continue;
            }

            $waId = $this->text($contact, 'wa_id');
            $profile = is_array($contact['profile'] ?? null) ? $contact['profile'] : [];
            $name = $this->text($profile, 'name');

            if ($waId !== null && $name !== null) {
                $names[$waId] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<int, array<string, mixed>>
     */
    private function messages(array $value): array
    {
        $messages = is_array($value['messages'] ?? null) ? $value['messages'] : [];

        return array_values(array_filter($messages, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, string>  $names
     */
    private function inbound(array $message, array $names, string $phoneNumberId): ?InboundSocialMessage
    {
        $from = $this->text($message, 'from');

        if ($from === null) {
            return null;
        }

        [$type, $body, $media] = $this->content($message);

        $inbound = new InboundSocialMessage(
            channel: SocialChannel::WhatsApp,
            // Their number is the thread. It is the same thread whichever of our
            // numbers they wrote to, which is what somebody expects: one
            // customer, one conversation.
            externalConversationId: $from,
            externalMessageId: $this->text($message, 'id'),
            channelAccountId: $phoneNumberId,
            participantExternalId: $from,
            participantName: $names[$from] ?? null,
            // The number as they use it, which is what an agent recognises.
            participantHandle: '+'.ltrim($from, '+'),
            type: $type,
            body: $body,
            media: $media,
            sentAt: $this->sentAt($message),
            // A click-to-WhatsApp advertisement puts the campaign here, on the
            // first message only. 12.11 attributes the lead from it.
            referral: is_array($message['referral'] ?? null) ? $message['referral'] : null,
        );

        return $inbound->hasContent() ? $inbound : null;
    }

    /**
     * What kind of message this is, and what it says.
     *
     * WhatsApp nests the content under a key named after the type, so the type
     * is read first and the body fetched from the matching key rather than
     * guessed at.
     *
     * @param  array<string, mixed>  $message
     * @return array{0: MessageType, 1: string|null, 2: array<int, array<string, mixed>>}
     */
    private function content(array $message): array
    {
        $rawType = $this->text($message, 'type') ?? 'text';
        $type = MessageType::fromMeta($rawType);
        $part = is_array($message[$rawType] ?? null) ? $message[$rawType] : [];

        if ($type === MessageType::Text) {
            return [$type, $this->text($part, 'body'), []];
        }

        if (! $type->hasMedia()) {
            // A location, a contact card, a reaction, an interactive reply.
            // Recorded as something rather than dropped: the thread saying
            // "Attachment" tells an agent to go and look, where an empty bubble
            // reads as a bug.
            return [$type, null, []];
        }

        return [$type, $this->text($part, 'caption'), [[
            'type' => $rawType,
            // Meta's media id, not a URL: WhatsApp media has to be fetched with
            // a token and expires, so the id is what is kept and the bytes are
            // pulled onto the private disk separately.
            'media_id' => $this->text($part, 'id'),
            'mime_type' => $this->text($part, 'mime_type'),
            'filename' => $this->text($part, 'filename'),
        ]]];
    }

    /**
     * Move any outbound messages the receipts name.
     *
     * @param  array<string, mixed>  $value
     */
    private function statuses(array $value): int
    {
        $moved = 0;

        foreach (is_array($value['statuses'] ?? null) ? $value['statuses'] : [] as $status) {
            if (! is_array($status)) {
                continue;
            }

            $id = $this->text($status, 'id');
            $state = $this->text($status, 'status');

            if ($id === null || $state === null) {
                continue;
            }

            $moved += ($this->receipts)(
                SocialChannel::WhatsApp,
                $id,
                $state,
                $this->timestamp($status),
                $this->failureReason($status),
            ) ? 1 : 0;
        }

        return $moved;
    }

    /**
     * Why Meta could not deliver it, when it says.
     *
     * @param  array<string, mixed>  $status
     */
    private function failureReason(array $status): ?string
    {
        foreach (is_array($status['errors'] ?? null) ? $status['errors'] : [] as $error) {
            if (! is_array($error)) {
                continue;
            }

            $title = $this->text($error, 'title');
            $detail = $this->text($error, 'error_data') === null
                ? null
                : $this->text(is_array($error['error_data']) ? $error['error_data'] : [], 'details');

            if ($title !== null) {
                return $detail === null ? $title : $title.': '.$detail;
            }
        }

        return null;
    }

    /**
     * Who owns a lead a conversation on this number produces.
     */
    private function owner(WhatsAppPhoneNumber $number): ?User
    {
        return $number->businessAccount?->account?->connectedBy;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function sentAt(array $message): ?Carbon
    {
        return $this->timestamp($message);
    }

    /**
     * WhatsApp counts in **seconds**, where Messenger counts in milliseconds.
     *
     * @param  array<string, mixed>  $values
     */
    private function timestamp(array $values): ?Carbon
    {
        $timestamp = $values['timestamp'] ?? null;

        return is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp) : null;
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
