<?php

namespace App\Domain\Shared\Duplicates;

use App\Domain\Accounts\AccountDuplicates;
use App\Domain\Contacts\ContactDuplicates;
use App\Domain\Leads\LeadDuplicates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

/**
 * The modules that take part in duplicate detection.
 *
 * The merge screen is one component serving three modules, so the module in the
 * URL has to resolve through here. Anything not listed 404s rather than being
 * turned into a class name — the same reason settings.group matches its
 * {group} segment against SettingsRegistry.
 */
final class DuplicateRegistry
{
    /**
     * @return array<string, class-string<DuplicateSource>>
     */
    public static function sources(): array
    {
        return [
            'leads' => LeadDuplicates::class,
            'contacts' => ContactDuplicates::class,
            'accounts' => AccountDuplicates::class,
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

    public static function find(string $module): ?DuplicateSource
    {
        $class = self::sources()[$module] ?? null;

        return $class === null ? null : App::make($class);
    }

    /**
     * The source that owns a model, or null if that model does not take part.
     *
     * Matched on the class rather than a name, so nothing here can be steered
     * by a value from a request.
     */
    public static function sourceFor(Model $record): ?DuplicateSource
    {
        foreach (self::all() as $source) {
            $class = $source->modelClass();

            if ($record instanceof $class) {
                return $source;
            }
        }

        return null;
    }

    /**
     * @return array<int, DuplicateSource>
     */
    public static function all(): array
    {
        return array_map(
            fn (string $class) => App::make($class),
            array_values(self::sources())
        );
    }
}
