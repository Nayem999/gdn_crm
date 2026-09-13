<?php

namespace App\Domain\Ingestion;

use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Leads\Models\Lead;
use Illuminate\Database\Eloquent\Model;

/**
 * What an inbound source is allowed to write into.
 *
 * A source stores a **module key**, never a class name, and it is matched here
 * and nowhere else — the same rule the import, merge and API registries follow.
 * A payload, a form field or a URL segment that could name `related_type` or a
 * model class would be an arbitrary instantiation one typo away.
 *
 * This is also what "a source can only write into its configured target module"
 * means in practice: the pipeline (8.4) resolves the module through this
 * registry using the *stored* key, so nothing in a payload can redirect a
 * delivery into a different table.
 *
 * Deliberately short. Every module listed here is a promise that an outside
 * system can create records in it, and that promise is worth making on purpose
 * rather than by accident every time somebody builds a screen.
 *
 * It is the same three modules importing covers, and that is not a coincidence:
 * a module can be written into from outside once it has declared which of its
 * fields may be written, what they must look like and how one is created —
 * which is what an `ImportSource` is. Deals have no such declaration yet, so
 * they are not a target; giving them one is a task of its own, not something
 * the gateway should decide on their behalf. `IngestionWritersTest` asserts
 * this list and IngestionWriters agree, so neither can grow without the other.
 */
final class IngestionTargets
{
    /**
     * @var array<string, class-string<Model>>
     */
    private const TARGETS = [
        'leads' => Lead::class,
        'contacts' => Contact::class,
        'accounts' => Account::class,
    ];

    /**
     * @return array<string, class-string<Model>>
     */
    public static function all(): array
    {
        return self::TARGETS;
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::TARGETS);
    }

    public static function has(string $module): bool
    {
        return array_key_exists($module, self::TARGETS);
    }

    /**
     * @return class-string<Model>|null
     */
    public static function modelClass(string $module): ?string
    {
        return self::TARGETS[$module] ?? null;
    }

    public static function label(string $module): string
    {
        return self::options()[$module] ?? $module;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'leads' => 'Leads',
            'contacts' => 'Contacts',
            'accounts' => 'Accounts',
        ];
    }
}
