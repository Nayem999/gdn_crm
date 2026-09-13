<?php

namespace App\Domain\Ingestion\DTOs;

/**
 * A freshly minted key and signing secret, in plain text.
 *
 * The only time either value exists outside the caller's own system. It is
 * returned by the issuing action, shown once, and then gone: the key is stored
 * hashed and cannot be recovered, and the signing secret — which is stored
 * encrypted, so it *could* be — is deliberately never read back to a screen,
 * because a page that decrypts a credential on demand is a page that decrypts a
 * credential on demand.
 *
 * Never log this, never put it in a notification, never return it from the API.
 */
readonly class SourceCredentials
{
    public function __construct(
        public string $key,
        public string $signingSecret,
    ) {}

    /**
     * Neither value appears in a stack trace, a dump or a serialised queue
     * payload. `__debugInfo` covers var_dump and dd; the array shape covers
     * anything that casts the object.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['key' => '[redacted]', 'signingSecret' => '[redacted]'];
    }

    public function __toString(): string
    {
        return '[redacted]';
    }
}
