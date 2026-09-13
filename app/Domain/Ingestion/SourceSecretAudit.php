<?php

namespace App\Domain\Ingestion;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ingestion\Models\DataSource;

/**
 * Records that a source's credentials changed — who, when, and which source.
 *
 * Never the value, never its length, never the old value, never even the hint.
 * A credential change is worth an audit entry precisely because somebody may
 * later need to ask who did it, and an entry that carried the secret would turn
 * the audit log into the one place it is written down in a readable form.
 *
 * Written here rather than by the trait: the secret columns are deliberately
 * absent from `DataSource::activityAttributes()`, so an ordinary save records
 * nothing about them. This is the explicit, value-free replacement.
 */
final class SourceSecretAudit
{
    public static function issued(DataSource $source, bool $rotated, ?string $graceEndsAt): void
    {
        $properties = [
            'source' => $source->name,
            'action' => $rotated ? 'rotated' : 'issued',
        ];

        if ($rotated) {
            // Worth recording, because "the old key still worked until then" is
            // the question somebody asks after an incident.
            $properties['previous_key_valid_until'] = $graceEndsAt ?? 'immediately revoked';
        }

        self::write(
            $source,
            $rotated
                ? 'Data source key was rotated'
                : 'Data source key was issued',
            $properties
        );
    }

    public static function revoked(DataSource $source): void
    {
        self::write($source, 'Data source key was revoked', [
            'source' => $source->name,
            'action' => 'revoked',
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private static function write(DataSource $source, string $description, array $properties): void
    {
        activity(AuditLogger::LOG_NAME)
            ->performedOn($source)
            ->event('updated')
            ->withProperties(['attributes' => $properties])
            ->log($description);
    }
}
