<?php

namespace App\Domain\Mail\Actions;

use App\Domain\Mail\Inbound\InboundMessageData;
use App\Domain\Mail\Inbound\ThreadMatcher;
use App\Domain\Mail\Models\InboundMessage;
use Illuminate\Database\QueryException;

/**
 * Store one arriving message, once.
 *
 * The Message-ID is unique in the table and the insert claims it, rather than a
 * check followed by an insert — IMAP hands the same message over again after
 * any interruption, and two syncs overlapping is the ordinary case, not an edge
 * one.
 */
class StoreInboundMessageAction
{
    public function __construct(private readonly ThreadMatcher $matcher) {}

    /**
     * @return InboundMessage|null Null when this message is already stored.
     */
    public function handle(InboundMessageData $message): ?InboundMessage
    {
        $match = $this->matcher->match($message);

        try {
            return InboundMessage::query()->create([
                'message_id' => $message->messageId,
                'in_reply_to' => $message->inReplyTo,
                'references' => $message->references === [] ? null : implode(' ', $message->references),
                'from_email' => $message->fromEmail,
                'from_name' => $message->fromName,
                'to_email' => $message->toEmail,
                'subject' => $message->subject,
                'body' => $message->body,
                'folder' => $message->folder,
                'uid' => $message->uid,
                'uid_validity' => $message->uidValidity,
                'related_type' => $match['related']?->getMorphClass(),
                'related_id' => $match['related']?->getKey(),
                'email_message_id' => $match['replyTo']?->id,
                'received_at' => $message->receivedAt,
            ]);
        } catch (QueryException $failure) {
            if (($failure->errorInfo[0] ?? null) === '23000') {
                return null;
            }

            throw $failure;
        }
    }
}
