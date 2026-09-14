<?php

namespace App\Domain\Install;

use App\Domain\Company\Models\Company;
use App\Models\User;

/**
 * Whether this installation has been set up, and the one place that decides it.
 *
 * The wizard creates an administrator without anybody being logged in, which
 * makes "is it installed?" a security question rather than a cosmetic one. It
 * is answered strictly:
 *
 *   - the marker on the company row, written when the wizard finishes; **or**
 *   - any user existing at all.
 *
 * Either closes the wizard. The second is the belt to the first's braces: an
 * installation seeded from the command line never wrote the marker, and its
 * `/install` must still be shut. The first is what stops deleting every user —
 * which an attacker cannot do without already being an administrator, but which
 * an administrator can do by accident — from reopening the door.
 */
final class Installation
{
    public static function isComplete(): bool
    {
        // The user check comes first because it is the cheap one: reading the
        // marker goes through Company::current(), which *creates* the company
        // row if it is missing, and the sign-in page asks this question on
        // every visit for the life of the installation.
        return User::query()->exists()
            || Company::current()->installed_at !== null;
    }

    public static function isPending(): bool
    {
        return ! self::isComplete();
    }

    /**
     * Write the marker. Called once, at the end of the wizard.
     */
    public static function markComplete(): void
    {
        $company = Company::current();

        if ($company->installed_at === null) {
            $company->forceFill(['installed_at' => now()])->save();
        }
    }
}
