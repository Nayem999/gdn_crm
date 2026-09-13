<?php

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\SourceSecretAudit;

/**
 * Takes a source's credentials away, now.
 *
 * No grace window, on purpose: the reason somebody revokes rather than rotates
 * is that the key has been somewhere it should not have been, and "it keeps
 * working for another day" is the opposite of what that situation needs.
 *
 * The source itself is left switched on. Revoking says "nobody may authenticate
 * as this" and leaves every other decision — which module it writes into, what
 * it has already delivered — untouched. A revoked source with no key cannot be
 * authenticated by anybody, including by sending nothing, because
 * SourceSecret::matches() treats a null stored hash as a miss.
 */
class RevokeSourceSecretAction
{
    public function __invoke(DataSource $source): void
    {
        $source->forceFill([
            'secret_hash' => null,
            'secret_hint' => null,
            'signing_secret' => null,
            'secret_created_at' => null,
            // The grace copy goes too. A revoke that left the previous key
            // alive would revoke nothing an attacker was actually using.
            'previous_secret_hash' => null,
            'previous_signing_secret' => null,
            'previous_secret_expires_at' => null,
            // Kept so the screen can say "revoked" rather than "never issued".
            'secret_revoked_at' => now(),
        ])->save();

        SourceSecretAudit::revoked($source);
    }
}
