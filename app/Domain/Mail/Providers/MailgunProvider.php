<?php

namespace App\Domain\Mail\Providers;

use App\Domain\Mail\Transports\MailgunTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

class MailgunProvider extends Provider
{
    /**
     * Mailgun keeps European accounts on a separate host, and an account posted
     * to the wrong one fails as "domain not found" — which reads like a DNS
     * problem rather than a region problem.
     */
    private const REGIONS = [
        'us' => 'United States — api.mailgun.net',
        'eu' => 'Europe — api.eu.mailgun.net',
    ];

    public function key(): string
    {
        return 'mailgun';
    }

    public function label(): string
    {
        return 'Mailgun';
    }

    public function description(): string
    {
        return 'Needs a sending domain and a private API key from the Mailgun dashboard.';
    }

    public function fields(): array
    {
        return [
            $this->text('domain', 'Mailgun sending domain', 'For example mg.example.com.'),
            $this->secret('secret', 'Mailgun private API key'),
            $this->select('region', 'Mailgun region', self::REGIONS, 'us'),
        ];
    }

    public function missingRequirements(array $credentials): array
    {
        return $this->missing($credentials, ['domain' => 'a sending domain', 'secret' => 'a private API key']);
    }

    public function transport(array $credentials): TransportInterface
    {
        return new MailgunTransport(
            (string) $this->credential($credentials, 'domain', ''),
            (string) $this->credential($credentials, 'secret', ''),
            $this->credential($credentials, 'region', 'us') === 'eu'
                ? 'https://api.eu.mailgun.net'
                : 'https://api.mailgun.net',
        );
    }
}
