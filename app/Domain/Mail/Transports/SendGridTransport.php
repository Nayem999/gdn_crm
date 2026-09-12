<?php

namespace App\Domain\Mail\Transports;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;

/**
 * SendGrid's v3 send endpoint.
 *
 * Two details SendGrid is strict about, and both are silent failures if you get
 * them wrong: the plain-text part must come before the HTML part in `content`,
 * and the id of the accepted message is in a response *header* rather than the
 * body — a 202 with an empty body is the success case.
 */
class SendGridTransport extends ApiTransport
{
    public function __construct(private readonly string $key)
    {
        parent::__construct();
    }

    protected function providerLabel(): string
    {
        return 'SendGrid';
    }

    protected function deliver(Email $email, Envelope $envelope): ?string
    {
        $personalisation = array_filter([
            'to' => $this->addresses($email->getTo()),
            'cc' => $this->addresses($email->getCc()),
            'bcc' => $this->addresses($email->getBcc()),
        ], fn (array $addresses): bool => $addresses !== []);

        $content = [];

        if (($text = $this->text($email)) !== null) {
            $content[] = ['type' => 'text/plain', 'value' => $text];
        }

        if (($html = $this->html($email)) !== null) {
            $content[] = ['type' => 'text/html', 'value' => $html];
        }

        $payload = [
            'personalizations' => [$personalisation],
            'from' => $this->contact($this->sender($email, $envelope)),
            'subject' => (string) $email->getSubject(),
            'content' => $content,
        ];

        $replyTo = $email->getReplyTo();

        if ($replyTo !== []) {
            $payload['reply_to'] = $this->contact($replyTo[0]);
        }

        $attachments = $this->attachments($email);

        if ($attachments !== []) {
            $payload['attachments'] = array_map(fn (array $attachment): array => [
                'content' => $attachment['content'],
                'filename' => $attachment['name'],
                'type' => $attachment['type'],
                'disposition' => 'attachment',
            ], $attachments);
        }

        $response = $this->request()
            ->withToken($this->key)
            ->post('https://api.sendgrid.com/v3/mail/send', $payload);

        if ($response->failed()) {
            throw $this->failed($response);
        }

        $id = $response->header('X-Message-Id');

        return $id === '' ? null : $id;
    }

    public function __toString(): string
    {
        return 'sendgrid+api://api.sendgrid.com';
    }
}
