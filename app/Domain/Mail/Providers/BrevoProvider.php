<?php

namespace App\Domain\Mail\Providers;

use App\Domain\Mail\Transports\BrevoTransport;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Transport\TransportInterface;

class BrevoProvider extends Provider
{
    public function key(): string
    {
        return 'brevo';
    }

    public function label(): string
    {
        return 'Brevo';
    }

    public function description(): string
    {
        return 'Needs a v3 API key. This is the transactional key, not the marketing one.';
    }

    public function fields(): array
    {
        return [
            $this->secret('key', 'Brevo API key'),
        ];
    }

    public function missingRequirements(array $credentials): array
    {
        return $this->missing($credentials, ['key' => 'an API key']);
    }

    public function transport(array $credentials): TransportInterface
    {
        return new BrevoTransport((string) $this->credential($credentials, 'key', ''));
    }

    public function verify(array $credentials): void
    {
        $this->verifyEndpoint(
            Http::withHeaders([
                'api-key' => (string) $this->credential($credentials, 'key', ''),
                'Accept' => 'application/json',
            ]),
            'https://api.brevo.com/v3/account',
        );
    }
}
