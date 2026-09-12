<?php

namespace App\Domain\Mail\Inbound;

/**
 * Somewhere messages arrive.
 *
 * An interface because IMAP is the only implementation that needs a mail
 * server, and everything worth testing — matching, storing, not importing the
 * same message twice — is on this side of it.
 */
interface InboundMailbox
{
    /**
     * Open the mailbox and come back quietly, or throw with the server's own
     * explanation.
     *
     * @throws \RuntimeException
     */
    public function connect(): void;

    /**
     * The folder's current UIDVALIDITY.
     *
     * When this changes, every UID previously recorded refers to nothing, and
     * the only safe reading is to start again.
     */
    public function uidValidity(): int;

    /**
     * Messages with a UID above the one given, oldest first.
     *
     * @return array<int, InboundMessageData>
     */
    public function since(int $uid, int $limit = 100): array;

    public function close(): void;
}
