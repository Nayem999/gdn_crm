<?php

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\DTOs\SourceCredentials;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\SourceSecret;
use App\Domain\Ingestion\SourceSecretAudit;

/**
 * Mints a source's credentials — first issue and rotation are the same act.
 *
 * The difference is only whether there was something to keep working: a first
 * issue has no previous key, a rotation moves the current pair aside with an
 * expiry. Writing them as two actions would mean two places that decide how a
 * credential is stored, and the one that drifts is always the one nobody looks
 * at.
 *
 * Returns the plain text **once**. Nothing else in the application can produce
 * it again: the key is stored hashed, and the signing secret, though stored
 * encrypted because HMAC verification needs it, is never read back to a screen.
 */
class IssueSourceSecretAction
{
    public function __invoke(DataSource $source, ?int $graceHours = null): SourceCredentials
    {
        $key = SourceSecret::generateKey();
        $signing = SourceSecret::generateSigningSecret();

        $attributes = [
            'secret_hash' => SourceSecret::hash($key),
            'secret_hint' => SourceSecret::hint($key),
            'signing_secret' => $signing,
            'secret_created_at' => now(),
            // Issuing a key un-revokes the source: the stamp says "there is no
            // key here on purpose", and there now is one.
            'secret_revoked_at' => null,
            ...$this->carryOver($source, $graceHours),
        ];

        $rotated = $source->secret_hash !== null;

        $source->forceFill($attributes)->save();

        // Who, when, which source — never the value. Written by the action so
        // every path that mints a credential is recorded, not only the screen.
        SourceSecretAudit::issued(
            $source,
            $rotated,
            $source->previous_secret_expires_at?->toDateTimeString()
        );

        return new SourceCredentials($key, $signing);
    }

    /**
     * What the old credentials become.
     *
     * The integration at the other end is somebody else's deploy. A rotation
     * that stopped the old key the instant it was pressed would mean nobody
     * ever rotated anything, so the previous pair keeps working for a window
     * the administrator configures — and the window is written as an **expiry
     * stamp**, not a duration, so changing the setting afterwards cannot
     * silently extend a rotation that already happened.
     *
     * A grace of zero drops the old key immediately, which is what somebody
     * rotating *because it leaked* wants.
     *
     * @return array<string, mixed>
     */
    private function carryOver(DataSource $source, ?int $graceHours): array
    {
        $graceHours ??= DataSource::rotationGraceHours();

        if ($source->secret_hash === null || $graceHours <= 0) {
            return [
                'previous_secret_hash' => null,
                'previous_signing_secret' => null,
                'previous_secret_expires_at' => null,
            ];
        }

        return [
            'previous_secret_hash' => $source->secret_hash,
            'previous_signing_secret' => $source->signing_secret,
            'previous_secret_expires_at' => now()->addHours($graceHours),
        ];
    }
}
