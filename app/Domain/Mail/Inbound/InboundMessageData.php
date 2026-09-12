<?php

namespace App\Domain\Mail\Inbound;

use Illuminate\Support\Carbon;

/**
 * One message as it was found in the mailbox.
 *
 * Deliberately plain: everything downstream — matching, storing, the timeline —
 * works on this rather than on an IMAP resource, so none of it needs a mail
 * server to be tested against.
 */
final readonly class InboundMessageData
{
    /**
     * @param  array<int, string>  $references  The thread, oldest first, as the
     *                                          sending client recorded it.
     */
    public function __construct(
        public string $messageId,
        public string $fromEmail,
        public ?string $fromName,
        public ?string $toEmail,
        public ?string $subject,
        public ?string $body,
        public Carbon $receivedAt,
        public int $uid,
        public int $uidValidity,
        public string $folder,
        public ?string $inReplyTo = null,
        public array $references = [],
    ) {}

    /**
     * Every id this message claims to be part of a conversation with, newest
     * first.
     *
     * In-Reply-To before References: it names the direct parent, which is the
     * better match when a long thread has drifted onto a different subject.
     *
     * @return array<int, string>
     */
    public function thread(): array
    {
        $ids = $this->inReplyTo === null ? [] : [$this->inReplyTo];

        foreach (array_reverse($this->references) as $reference) {
            if (! in_array($reference, $ids, true)) {
                $ids[] = $reference;
            }
        }

        return $ids;
    }
}
