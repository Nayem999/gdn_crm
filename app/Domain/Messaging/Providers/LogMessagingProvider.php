<?php

namespace App\Domain\Messaging\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes the message to the application log instead of sending it.
 *
 * The same reasoning as the mail log provider: "nothing is configured" should
 * be a state the system can be in rather than a hole, and a developer machine
 * has credentials for nothing. It counts as configured because it does exactly
 * what it says.
 */
class LogMessagingProvider extends Provider
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

    public function verify(array $credentials): void
    {
        // Nothing to reach.
    }

    public function send(array $credentials, string $to, string $body): string
    {
        $id = (string) Str::uuid();

        Log::debug('Message to '.$to, ['body' => $body, 'id' => $id]);

        return $id;
    }
}
