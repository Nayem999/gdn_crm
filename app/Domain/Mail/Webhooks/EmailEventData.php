<?php

namespace App\Domain\Mail\Webhooks;

use App\Domain\Mail\Enums\EmailEventType;
use Illuminate\Support\Carbon;

/**
 * One provider event, in the application's own terms.
 *
 * Parsers produce these; nothing downstream of them knows which provider a
 * payload came from or what it called any of this.
 */
final readonly class EmailEventData
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $messageId,
        public EmailEventType $type,
        public Carbon $occurredAt,
        public ?string $url = null,
        public ?string $reason = null,
        public array $payload = [],
        /**
         * Which recipient this is about, when the provider says.
         *
         * One message to three people is three rows in the log, because a
         * bounce for one of them is a fact about that one. An event that names
         * nobody applies to all of them, which is the right answer for a
         * provider that reports per message rather than per recipient.
         */
        public ?string $recipient = null,
    ) {}

    /**
     * A fingerprint of the event itself, so a redelivery is recognised.
     *
     * Built from what the event *is* rather than from any id the provider
     * supplies, because half of them supply none — and because a provider that
     * retries with a fresh id is still telling us the same thing. The clicked
     * URL is part of it: two clicks on two different links a second apart are
     * two events, and two reports of the same click are one.
     */
    public function signature(string $provider, string $recipient): string
    {
        return hash('sha256', implode('|', [
            $provider,
            $this->messageId,
            $recipient,
            $this->type->value,
            $this->occurredAt->toIso8601String(),
            $this->url ?? '',
        ]));
    }
}
