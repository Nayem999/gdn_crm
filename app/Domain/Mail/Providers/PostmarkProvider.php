<?php

namespace App\Domain\Mail\Providers;

use App\Domain\Mail\Transports\PostmarkTransport;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Transport\TransportInterface;

class PostmarkProvider extends Provider
{
    public function key(): string
    {
        return 'postmark';
    }

    public function label(): string
    {
        return 'Postmark';
    }

    public function description(): string
    {
        return 'Needs a server API token. The message stream decides which of the server\'s streams the mail is billed and reported against.';
    }

    public function fields(): array
    {
        return [
            $this->secret('token', 'Postmark server API token'),
            $this->text('stream', 'Postmark message stream', 'Defaults to outbound, which is the transactional stream.'),
        ];
    }

    public function missingRequirements(array $credentials): array
    {
        return $this->missing($credentials, ['token' => 'a server API token']);
    }

    public function transport(array $credentials): TransportInterface
    {
        return new PostmarkTransport(
            (string) $this->credential($credentials, 'token', ''),
            (string) $this->credential($credentials, 'stream', 'outbound'),
        );
    }

    public function verify(array $credentials): void
    {
        $this->verifyEndpoint(
            Http::withHeaders([
                'X-Postmark-Server-Token' => (string) $this->credential($credentials, 'token', ''),
                'Accept' => 'application/json',
            ]),
            'https://api.postmarkapp.com/server',
        );
    }
}
