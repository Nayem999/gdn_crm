<?php

namespace App\Domain\Social\DTOs;

use App\Domain\Social\Enums\MessageType;
use App\Domain\Social\Enums\SocialChannel;
use Illuminate\Support\Carbon;

/**
 * One message arriving from outside, already read out of somebody else's
 * payload and into terms this application uses.
 *
 * A value object rather than ten parameters, and it exists so that the parsing
 * of Meta's envelope happens in exactly one place per channel: the handler reads
 * the payload and builds one of these, and `RecordInboundMessageAction` never
 * sees a webhook. That is what lets 12.10 add WhatsApp by writing a second
 * parser rather than a second recorder — and what keeps the untrusted document
 * away from the code that writes records.
 */
readonly class InboundSocialMessage
{
    /**
     * @param  string  $externalConversationId  Meta's thread id — the PSID for
     *                                          Messenger, the customer's number
     *                                          for WhatsApp.
     * @param  array<int, array<string, mixed>>  $media  Attachments as they arrived.
     * @param  array<string, mixed>|null  $referral  The advertisement that started
     *                                               this, when Meta says. 12.11
     *                                               attributes from it.
     */
    public function __construct(
        public SocialChannel $channel,
        public string $externalConversationId,
        public ?string $externalMessageId = null,
        public ?string $channelAccountId = null,
        public ?string $participantExternalId = null,
        public ?string $participantName = null,
        public ?string $participantHandle = null,
        public MessageType $type = MessageType::Text,
        public ?string $body = null,
        public array $media = [],
        public ?Carbon $sentAt = null,
        public ?array $referral = null,
    ) {}

    /**
     * When it was sent, or now.
     *
     * Meta's moment where it gave one: the reply window is measured from the
     * customer's message, so taking our own clock would quietly hand an agent
     * more time than Meta will actually allow.
     */
    public function sentAt(): Carbon
    {
        return $this->sentAt ?? Carbon::now();
    }

    /**
     * Whether there is anything here worth showing in a thread.
     *
     * A delivery receipt or a read receipt arrives on the same webhook as a
     * message and carries neither body nor attachment; recording one as a
     * message would put empty bubbles in the conversation.
     */
    public function hasContent(): bool
    {
        return trim((string) $this->body) !== '' || $this->media !== [];
    }
}
