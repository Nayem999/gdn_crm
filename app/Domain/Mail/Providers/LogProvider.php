<?php

namespace App\Domain\Mail\Providers;

use Illuminate\Mail\Transport\LogTransport;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Writes the message to the application log instead of sending it.
 *
 * It exists so that "no provider has been configured yet" is a *state the
 * system can be in* rather than a hole. A fresh installation, and every
 * developer machine, has no credentials for anything; without this the first
 * notification would either throw or be silently dropped, and neither is a good
 * way to find out that email was never set up.
 *
 * It counts as configured, because it does exactly what it promises.
 */
class LogProvider extends Provider
{
    public function key(): string
    {
        return 'log';
    }

    public function label(): string
    {
        return 'Log only — nothing is sent';
    }

    public function description(): string
    {
        return 'Messages are written to the application log. Use this until a real provider is configured.';
    }

    public function fields(): array
    {
        return [];
    }

    public function missingRequirements(array $credentials): array
    {
        return [];
    }

    public function transport(array $credentials): TransportInterface
    {
        return new LogTransport(Log::channel(config('mail.mailers.log.channel') ?? config('logging.default')));
    }

    public function verify(array $credentials): void
    {
        // Nothing to reach. Saying so is the honest answer, not a no-op hiding
        // a missing check.
    }
}
