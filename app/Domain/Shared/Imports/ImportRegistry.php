<?php

namespace App\Domain\Shared\Imports;

use App\Domain\Accounts\AccountImportSource;
use App\Domain\Contacts\ContactImportSource;
use App\Domain\Leads\LeadImportSource;
use Illuminate\Support\Facades\App;

/**
 * The modules a file can be imported into.
 *
 * The import screen is one component serving three modules, so the module in
 * the URL resolves through here and anything unlisted 404s rather than being
 * turned into a class name.
 */
final class ImportRegistry
{
    /**
     * @return array<string, class-string<ImportSource>>
     */
    public static function sources(): array
    {
        return [
            'leads' => LeadImportSource::class,
            'contacts' => ContactImportSource::class,
            'accounts' => AccountImportSource::class,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::sources());
    }

    public static function has(string $module): bool
    {
        return array_key_exists($module, self::sources());
    }

    public static function find(string $module): ?ImportSource
    {
        $class = self::sources()[$module] ?? null;

        return $class === null ? null : App::make($class);
    }
}
