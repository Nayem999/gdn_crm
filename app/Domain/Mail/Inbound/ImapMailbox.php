<?php

namespace App\Domain\Mail\Inbound;

use Illuminate\Support\Carbon;
use IMAP\Connection;
use RuntimeException;

/**
 * A real mailbox, over IMAP.
 *
 * This is the one part of inbound mail that cannot be tested without a mail
 * server, which is why it is as thin as it can be: connect, list UIDs, decode
 * the parts, hand back plain data. Everything worth arguing about — matching a
 * message to a record, not importing it twice, what happens when the mailbox
 * renumbers — happens on the other side of InboundMailbox, against a fake.
 *
 * It uses PHP's IMAP extension. That extension is unbundled from PHP 8.4
 * onwards, so a server that does not have it gets a clear message here rather
 * than a fatal error somewhere downstream.
 */
class ImapMailbox implements InboundMailbox
{
    private ?Connection $stream = null;

    public function __construct(private readonly MailboxSettings $settings) {}

    public static function isAvailable(): bool
    {
        return function_exists('imap_open');
    }

    public function connect(): void
    {
        if (! self::isAvailable()) {
            throw new RuntimeException('This server does not have PHP\'s IMAP extension installed, so inbound mail cannot be read.');
        }

        // Errors are collected rather than raised, and the last one is the
        // useful one — "authentication failed" versus "connection refused" are
        // different problems for the administrator.
        $stream = @imap_open($this->settings->connectionString(), $this->settings->username, $this->settings->password, 0, 1);

        if ($stream === false) {
            $errors = imap_errors();
            imap_alerts();

            throw new RuntimeException($errors === false || $errors === []
                ? 'Could not open the mailbox.'
                : (string) end($errors));
        }

        $this->stream = $stream;
    }

    public function uidValidity(): int
    {
        $status = @imap_status($this->connection(), $this->settings->connectionString(), SA_UIDVALIDITY);

        return $status === false ? 0 : (int) $status->uidvalidity;
    }

    public function since(int $uid, int $limit = 100): array
    {
        $connection = $this->connection();

        // "n:*" is inclusive of n, and n is one we already have — so the range
        // starts one past it. IMAP has no exclusive form.
        $range = ($uid + 1).':*';

        $overviews = @imap_fetch_overview($connection, $range, FT_UID);

        if ($overviews === false) {
            return [];
        }

        $messages = [];

        foreach ($overviews as $overview) {
            $messageUid = (int) ($overview->uid ?? 0);

            // A range ending in "*" always returns the last message, even when
            // its UID is below the start, so the filter is not redundant.
            if ($messageUid <= $uid) {
                continue;
            }

            $messages[] = $this->read($connection, $messageUid, $overview);

            if (count($messages) >= $limit) {
                break;
            }
        }

        usort($messages, fn (InboundMessageData $a, InboundMessageData $b): int => $a->uid <=> $b->uid);

        return $messages;
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            imap_close($this->stream);
            $this->stream = null;
        }
    }

    private function connection(): Connection
    {
        if ($this->stream === null) {
            $this->connect();
        }

        if ($this->stream === null) {
            throw new RuntimeException('The mailbox is not open.');
        }

        return $this->stream;
    }

    private function read(Connection $connection, int $uid, object $overview): InboundMessageData
    {
        $headers = imap_rfc822_parse_headers((string) imap_fetchheader($connection, $uid, FT_UID));

        $from = $headers->from[0] ?? null;
        $to = $headers->to[0] ?? null;

        $fromEmail = $from === null ? '' : strtolower(($from->mailbox ?? '').'@'.($from->host ?? ''));

        $messageId = isset($headers->message_id) ? trim((string) $headers->message_id, '<>') : '';

        $body = $this->body($connection, $uid);

        return new InboundMessageData(
            // A message with no id of its own still has to be identifiable, or
            // every sync imports it again.
            messageId: $messageId !== '' ? $messageId : $this->syntheticId($uid, $fromEmail, $body),
            fromEmail: $fromEmail,
            fromName: isset($from->personal) ? $this->decode((string) $from->personal) : null,
            toEmail: $to === null ? null : strtolower(($to->mailbox ?? '').'@'.($to->host ?? '')),
            subject: isset($headers->subject) ? $this->decode((string) $headers->subject) : null,
            body: $body,
            receivedAt: Carbon::parse((string) ($overview->date ?? 'now')),
            uid: $uid,
            uidValidity: $this->uidValidity(),
            folder: $this->settings->folder,
            inReplyTo: isset($headers->in_reply_to) ? trim((string) $headers->in_reply_to, '<> ') : null,
            references: $this->referencesOf($headers),
        );
    }

    /**
     * The plain-text part, falling back to the HTML one stripped of its markup.
     *
     * Plain text first on purpose: it is what the sender's client thought the
     * message said, and the HTML alternative of a reply is usually the entire
     * quoted thread wrapped in styling.
     */
    private function body(Connection $connection, int $uid): ?string
    {
        $structure = @imap_fetchstructure($connection, $uid, FT_UID);

        if ($structure === false) {
            return null;
        }

        if (! isset($structure->parts) || ! is_array($structure->parts)) {
            return $this->decodePart((string) imap_body($connection, $uid, FT_UID), (int) ($structure->encoding ?? 0));
        }

        $html = null;

        foreach ($this->flatten($structure->parts) as $section => $part) {
            $content = (string) imap_fetchbody($connection, $uid, (string) $section, FT_UID);
            $decoded = $this->decodePart($content, (int) ($part->encoding ?? 0));

            // Type 0 is text; anything else with a PLAIN subtype is not a body.
            if ((int) ($part->type ?? 0) === 0 && ($part->subtype ?? '') === 'PLAIN') {
                return $decoded;
            }

            if ($html === null && ($part->subtype ?? '') === 'HTML') {
                $html = $decoded;
            }
        }

        return $html === null ? null : trim(strip_tags($html));
    }

    /**
     * Parts keyed by the section number IMAP wants them fetched by, walking
     * into multipart/alternative rather than stopping at it.
     *
     * @param  array<int, object>  $parts
     * @return array<string, object>
     */
    private function flatten(array $parts, string $prefix = ''): array
    {
        $flat = [];

        foreach ($parts as $index => $part) {
            $section = $prefix === '' ? (string) ($index + 1) : $prefix.'.'.($index + 1);

            if (isset($part->parts) && is_array($part->parts)) {
                $flat += $this->flatten($part->parts, $section);

                continue;
            }

            $flat[$section] = $part;
        }

        return $flat;
    }

    private function decodePart(string $content, int $encoding): string
    {
        return match ($encoding) {
            3 => (string) base64_decode($content, true),
            4 => quoted_printable_decode($content),
            default => $content,
        };
    }

    private function decode(string $value): string
    {
        $parts = imap_mime_header_decode($value);

        if ($parts === false) {
            return $value;
        }

        $decoded = '';

        foreach ($parts as $part) {
            $charset = strtoupper((string) $part->charset);
            $text = (string) $part->text;

            $decoded .= $charset === 'DEFAULT' || $charset === 'UTF-8'
                ? $text
                : (string) mb_convert_encoding($text, 'UTF-8', $charset);
        }

        return $decoded;
    }

    /**
     * @return array<int, string>
     */
    private function referencesOf(object $headers): array
    {
        $raw = isset($headers->references) ? (string) $headers->references : '';

        if ($raw === '') {
            return [];
        }

        preg_match_all('/<([^>]+)>/', $raw, $matches);

        return $matches[1];
    }

    private function syntheticId(int $uid, string $from, ?string $body): string
    {
        return 'synthetic-'.hash('sha256', implode('|', [$this->settings->folder, $uid, $from, $body ?? '']));
    }
}
