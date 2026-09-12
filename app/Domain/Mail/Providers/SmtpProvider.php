<?php

namespace App\Domain\Mail\Providers;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Plain SMTP — somebody else's relay, or the company's own mail server.
 *
 * It is the provider that works when none of the others apply, and the one an
 * administrator can point at a local catcher while testing.
 */
class SmtpProvider extends Provider
{
    public function key(): string
    {
        return 'smtp';
    }

    public function label(): string
    {
        return 'SMTP server';
    }

    public function description(): string
    {
        return 'Any mail server that speaks SMTP, including your own.';
    }

    public function fields(): array
    {
        return [
            $this->text('host', 'SMTP host', 'For example smtp.example.com.'),
            $this->integer('port', 'SMTP port', 587),
            $this->select('encryption', 'SMTP encryption', [
                'tls' => 'STARTTLS — usually port 587',
                'ssl' => 'SSL/TLS — usually port 465',
                'none' => 'None — only for a local relay',
            ], 'tls'),
            $this->text('username', 'SMTP username'),
            $this->secret('password', 'SMTP password'),
        ];
    }

    public function missingRequirements(array $credentials): array
    {
        return $this->missing($credentials, ['host' => 'a host', 'port' => 'a port']);
    }

    public function transport(array $credentials): TransportInterface
    {
        $encryption = (string) $this->credential($credentials, 'encryption', 'tls');

        // Null is Symfony's "decide at connection time": STARTTLS if the server
        // offers it. Only "ssl" means TLS from the first byte, and only "none"
        // turns the upgrade off — which is a choice about a local relay, not a
        // default anybody should reach for.
        $transport = new EsmtpTransport(
            (string) $this->credential($credentials, 'host', 'localhost'),
            (int) $this->credential($credentials, 'port', 587),
            $encryption === 'ssl' ? true : null,
        );

        $transport->setAutoTls($encryption !== 'none');

        $username = $this->credential($credentials, 'username');

        if ($username !== null) {
            $transport->setUsername((string) $username);
            $transport->setPassword((string) $this->credential($credentials, 'password', ''));
        }

        return $transport;
    }
}
