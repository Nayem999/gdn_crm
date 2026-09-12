<?php

namespace App\Domain\Mail\Transports;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;

/**
 * Postmark's single-message endpoint.
 *
 * The message stream is sent explicitly. Postmark keeps transactional and
 * broadcast mail in separate streams with separate reputations, and a message
 * posted without one lands in `outbound` — which is right for a CRM's
 * notifications but wrong for anything bulk, so it is a setting rather than an
 * assumption.
 */
class PostmarkTransport extends ApiTransport
{
    public function __construct(
        private readonly string $token,
        private readonly string $messageStream = 'outbound',
    ) {
        parent::__construct();
    }

    protected function providerLabel(): string
    {
        return 'Postmark';
    }

    protected function deliver(Email $email, Envelope $envelope): ?string
    {
        $payload = array_filter([
            'From' => $this->sender($email, $envelope)->toString(),
            'To' => $this->addressList($email->getTo()),
            'Cc' => $this->addressList($email->getCc()),
            'Bcc' => $this->addressList($email->getBcc()),
            'ReplyTo' => $this->addressList($email->getReplyTo()),
            'Subject' => $email->getSubject(),
            'HtmlBody' => $this->html($email),
            'TextBody' => $this->text($email),
            'MessageStream' => $this->messageStream,
        ], fn (?string $value): bool => $value !== null && $value !== '');

        $attachments = $this->attachments($email);

        if ($attachments !== []) {
            $payload['Attachments'] = array_map(fn (array $attachment): array => [
                'Name' => $attachment['name'],
                'Content' => $attachment['content'],
                'ContentType' => $attachment['type'],
            ], $attachments);
        }

        $response = $this->request()
            ->withHeaders(['X-Postmark-Server-Token' => $this->token, 'Accept' => 'application/json'])
            ->post('https://api.postmarkapp.com/email', $payload);

        if ($response->failed()) {
            throw $this->failed($response);
        }

        $id = $response->json('MessageID');

        return is_string($id) ? $id : null;
    }

    public function __toString(): string
    {
        return 'postmark+api://'.$this->messageStream;
    }
}
