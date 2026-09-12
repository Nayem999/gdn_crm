<?php

namespace App\Domain\Mail\Transports;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

/**
 * The shared half of every provider that sends over HTTP rather than SMTP.
 *
 * All four of them do the same three things — turn a MIME message into the
 * provider's own shape, post it, and keep the message id the provider hands
 * back — and differ only in the shape. So the shape is all a subclass writes.
 *
 * **The message id matters more than it looks.** It is the only thing that
 * connects a message we sent to the bounce, complaint or open the provider
 * reports later; 7.3's delivery log is built on it, and a transport that throws
 * it away makes that log unjoinable after the fact.
 */
abstract class ApiTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();

        // A raw MIME string has no headers to read, and none of these APIs take
        // one: they want the parts named. Nothing in the application sends that
        // way, and if something starts to, it should say so here rather than
        // post an empty message.
        if (! $original instanceof Message) {
            throw new TransportException($this->providerLabel().' cannot send a pre-built raw MIME message.');
        }

        $email = MessageConverter::toEmail($original);

        $id = $this->deliver($email, $message->getEnvelope());

        if ($id !== null && $id !== '') {
            $message->setMessageId($id);
        }
    }

    /**
     * Send it, and return the provider's own id for the message.
     */
    abstract protected function deliver(Email $email, Envelope $envelope): ?string;

    /**
     * The name this provider is known by, for error messages.
     */
    abstract protected function providerLabel(): string;

    protected function request(): PendingRequest
    {
        return Http::timeout(30)->connectTimeout(10);
    }

    /**
     * Turn a failed call into something an administrator can act on.
     *
     * The provider's own words are kept: "Domain not found" and "Unauthorized"
     * need different fixes, and collapsing both into "sending failed" is how
     * somebody spends an afternoon re-typing a correct API key.
     */
    protected function failed(Response $response): TransportException
    {
        $message = $this->errorFrom($response);

        return new TransportException(sprintf(
            '%s refused the message (HTTP %d)%s',
            $this->providerLabel(),
            $response->status(),
            $message === null ? '.' : ': '.$message,
        ));
    }

    private function errorFrom(Response $response): ?string
    {
        $body = $response->json();

        if (is_array($body)) {
            foreach (['message', 'Message', 'error', 'detail'] as $key) {
                if (isset($body[$key]) && is_string($body[$key])) {
                    return $body[$key];
                }
            }

            // SendGrid reports a list of problems rather than one.
            if (isset($body['errors']) && is_array($body['errors'])) {
                $messages = array_filter(array_map(
                    fn ($error) => is_array($error) && isset($error['message']) && is_string($error['message']) ? $error['message'] : null,
                    $body['errors']
                ));

                if ($messages !== []) {
                    return implode('; ', $messages);
                }
            }
        }

        $text = trim($response->body());

        return $text === '' ? null : mb_substr($text, 0, 300);
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, array{email: string, name?: string}>
     */
    protected function addresses(array $addresses): array
    {
        return array_map(fn (Address $address): array => $this->contact($address), $addresses);
    }

    /**
     * One address in the shape the JSON APIs expect.
     *
     * The name is left out rather than sent empty: an empty display name is a
     * validation error at more than one of these providers.
     *
     * @return array{email: string, name?: string}
     */
    protected function contact(Address $address): array
    {
        $name = $address->getName();

        return $name === ''
            ? ['email' => $address->getAddress()]
            : ['email' => $address->getAddress(), 'name' => $name];
    }

    /**
     * @param  array<int, Address>  $addresses
     */
    protected function addressList(array $addresses): string
    {
        return implode(', ', array_map(fn (Address $address): string => $address->toString(), $addresses));
    }

    /**
     * Every attachment, as filename, media type and base64 content.
     *
     * @return array<int, array{name: string, type: string, content: string}>
     */
    protected function attachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $attachments[] = [
                'name' => $this->filenameOf($attachment),
                'type' => $attachment->getMediaType().'/'.$attachment->getMediaSubtype(),
                'content' => base64_encode($attachment->getBody()),
            ];
        }

        return $attachments;
    }

    protected function filenameOf(DataPart $attachment): string
    {
        $name = $attachment->getPreparedHeaders()->getHeaderParameter('content-disposition', 'filename');

        return $name === null || $name === '' ? 'attachment' : $name;
    }

    protected function html(Email $email): ?string
    {
        $body = $email->getHtmlBody();

        return is_string($body) ? $body : null;
    }

    protected function text(Email $email): ?string
    {
        $body = $email->getTextBody();

        return is_string($body) ? $body : null;
    }

    /**
     * The sender, which every one of these APIs insists on having explicitly.
     */
    protected function sender(Email $email, Envelope $envelope): Address
    {
        $from = $email->getFrom();

        return $from[0] ?? $envelope->getSender();
    }
}
