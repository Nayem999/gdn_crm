<?php

namespace App\Domain\Mail\Providers;

use App\Domain\Mail\Transports\SendGridTransport;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Transport\TransportInterface;

class SendGridProvider extends Provider
{
    public function key(): string
    {
        return 'sendgrid';
    }

    public function label(): string
    {
        return 'SendGrid';
    }

    public function description(): string
    {
        return 'Needs an API key with Mail Send permission. The from address must be a verified sender or a domain SendGrid has authenticated.';
    }

    public function fields(): array
    {
        return [
            $this->secret('key', 'SendGrid API key'),
        ];
    }

    public function missingRequirements(array $credentials): array
    {
        return $this->missing($credentials, ['key' => 'an API key']);
    }

    public function transport(array $credentials): TransportInterface
    {
        return new SendGridTransport((string) $this->credential($credentials, 'key', ''));
    }

    public function verify(array $credentials): void
    {
        $this->verifyEndpoint(
            Http::withToken((string) $this->credential($credentials, 'key', '')),
            'https://api.sendgrid.com/v3/scopes',
        );
    }
}
