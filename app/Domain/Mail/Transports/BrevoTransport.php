<?php

namespace App\Domain\Mail\Transports;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;

/**
 * Brevo's transactional email endpoint.
 *
 * Brevo takes one reply-to address rather than a list, and names its
 * attachment content field without a media type — it works the type out from
 * the file name, so the name has to be a real one.
 */
class BrevoTransport extends ApiTransport
{
    public function __construct(private readonly string $key)
    {
        parent::__construct();
    }

    protected function providerLabel(): string
    {
        return 'Brevo';
    }

    protected function deliver(Email $email, Envelope $envelope): ?string
    {
        $payload = array_filter([
            'sender' => $this->contact($this->sender($email, $envelope)),
            'to' => $this->addresses($email->getTo()),
            'cc' => $this->addresses($email->getCc()),
            'bcc' => $this->addresses($email->getBcc()),
            'subject' => (string) $email->getSubject(),
            'htmlContent' => $this->html($email),
            'textContent' => $this->text($email),
        ], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);

        $replyTo = $email->getReplyTo();

        if ($replyTo !== []) {
            $payload['replyTo'] = $this->contact($replyTo[0]);
        }

        $attachments = $this->attachments($email);

        if ($attachments !== []) {
            $payload['attachment'] = array_map(fn (array $attachment): array => [
                'name' => $attachment['name'],
                'content' => $attachment['content'],
            ], $attachments);
        }

        $response = $this->request()
            ->withHeaders(['api-key' => $this->key, 'Accept' => 'application/json'])
            ->post('https://api.brevo.com/v3/smtp/email', $payload);

        if ($response->failed()) {
            throw $this->failed($response);
        }

        $id = $response->json('messageId');

        return is_string($id) ? trim($id, '<>') : null;
    }

    public function __toString(): string
    {
        return 'brevo+api://api.brevo.com';
    }
}
