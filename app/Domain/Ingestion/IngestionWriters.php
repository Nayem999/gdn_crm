<?php

namespace App\Domain\Ingestion;

use App\Domain\Accounts\AccountImportSource;
use App\Domain\Accounts\Actions\UpdateAccountAction;
use App\Domain\Accounts\DTOs\AccountData;
use App\Domain\Accounts\Models\Account;
use App\Domain\Contacts\Actions\UpdateContactAction;
use App\Domain\Contacts\ContactImportSource;
use App\Domain\Contacts\DTOs\ContactData;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Ingestion\Writers\ImportBackedWriter;
use App\Domain\Leads\Actions\UpdateLeadAction;
use App\Domain\Leads\DTOs\LeadData;
use App\Domain\Leads\LeadImportSource;
use App\Domain\Leads\Models\Lead;

/**
 * How the pipeline writes into each module it is allowed to write into.
 *
 * Matched by **module key**, never by anything from a payload — the same rule
 * IngestionTargets follows, and this is the other half of it: the target module
 * a source stores resolves here and nowhere else.
 *
 * The list is the same three modules importing covers, and that is not a
 * coincidence. A module can be written into from outside once it has declared
 * which of its fields may be written, what they must look like, and how one is
 * created — which is exactly what an `ImportSource` is. A module without one
 * has not made that declaration, and inventing it here would be Phase 8
 * deciding on its behalf.
 */
final class IngestionWriters
{
    /**
     * @return array<string, ImportBackedWriter>
     */
    public static function all(): array
    {
        return [
            'leads' => new ImportBackedWriter(
                app(LeadImportSource::class), Lead::class, LeadData::class, UpdateLeadAction::class,
            ),
            'contacts' => new ImportBackedWriter(
                app(ContactImportSource::class), Contact::class, ContactData::class, UpdateContactAction::class,
            ),
            'accounts' => new ImportBackedWriter(
                app(AccountImportSource::class), Account::class, AccountData::class, UpdateAccountAction::class,
            ),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $module): bool
    {
        return in_array($module, self::keys(), true);
    }

    public static function for(string $module): ?ImportBackedWriter
    {
        return self::all()[$module] ?? null;
    }
}
