<?php

namespace App\Domain\Mail\Actions;

use App\Domain\Mail\Inbound\InboundMailbox;
use App\Domain\Mail\Models\InboundMessage;

/**
 * Read what has arrived since last time.
 *
 * The cursor is not stored anywhere of its own: it is the highest UID already
 * imported from this folder, which cannot drift out of step with the table it
 * is derived from. A separate cursor row could — and the failure of one that
 * has run ahead is silently skipped mail.
 *
 * **The cursor is scoped by UIDVALIDITY.** A mailbox that renumbers — a
 * restore, a rebuild, a folder recreated with the same name — bumps that value,
 * and every UID recorded before it means nothing. Reading the highest UID
 * within the *current* validity therefore starts again from zero after a
 * renumber, which is the only safe answer; the Message-ID index is what stops
 * that re-reading the whole mailbox into duplicate rows.
 */
class SyncInboundEmailAction
{
    public function __construct(private readonly StoreInboundMessageAction $store) {}

    /**
     * @return array{read: int, stored: int}
     */
    public function handle(InboundMailbox $mailbox, string $folder, int $limit = 100): array
    {
        $mailbox->connect();

        try {
            $validity = $mailbox->uidValidity();

            $cursor = (int) InboundMessage::query()
                ->where('folder', $folder)
                ->where('uid_validity', $validity)
                ->max('uid');

            $messages = $mailbox->since($cursor, $limit);

            $stored = 0;

            foreach ($messages as $message) {
                if ($this->store->handle($message) !== null) {
                    $stored++;
                }
            }

            return ['read' => count($messages), 'stored' => $stored];
        } finally {
            $mailbox->close();
        }
    }
}
