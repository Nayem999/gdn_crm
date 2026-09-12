<?php

namespace App\Domain\Mail\Transports;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;

/**
 * Mailgun's messages endpoint.
 *
 * Mailgun takes form fields rather than JSON, and addresses as RFC strings
 * rather than objects, so this is the one of the four that posts multipart.
 */
class MailgunTransport extends ApiTransport
{
    public function __construct(
        private readonly string $domain,
        private readonly string $secret,
        private readonly string $endpoint = 'https://api.mailgun.net',
    ) {
        parent::__construct();
    }

    protected function providerLabel(): string
    {
        return 'Mailgun';
    }

    protected function deliver(Email $email, Envelope $envelope): ?string
    {
        $fields = array_filter([
            'from' => $this->sender($email, $envelope)->toString(),
            'to' => $this->addressList($email->getTo()),
            'cc' => $this->addressList($email->getCc()),
            'bcc' => $this->addressList($email->getBcc()),
            'h:Reply-To' => $this->addressList($email->getReplyTo()),
            'subject' => $email->getSubject(),
            'html' => $this->html($email),
            'text' => $this->text($email),
        ], fn (?string $value): bool => $value !== null && $value !== '');

        $request = $this->request()->asForm()->withBasicAuth('api', $this->secret);

        foreach ($this->attachments($email) as $attachment) {
            $request = $request->attach(
                'attachment',
                (string) base64_decode($attachment['content'], true),
                $attachment['name'],
                ['Content-Type' => $attachment['type']],
            );
        }

        $response = $request->post(rtrim($this->endpoint, '/').'/v3/'.$this->domain.'/messages', $fields);

        if ($response->failed()) {
            throw $this->failed($response);
        }

        $id = $response->json('id');

        // Mailgun returns the id wrapped in angle brackets; Symfony wants it
        // bare, so that what we store matches what the webhook reports.
        return is_string($id) ? trim($id, '<>') : null;
    }

    public function __toString(): string
    {
        return 'mailgun+api://'.$this->domain;
    }
}
