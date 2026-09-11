<?php

namespace App\Domain\CustomFields;

use App\Domain\CustomFields\Models\CustomField;
use Illuminate\Support\Collection;

/**
 * The active field definitions for each module, read once per request.
 *
 * A list screen asks for its columns and its filter fields several times per
 * render, and both are built from these definitions. Without a memo that is a
 * handful of identical queries per page — small, indexed, and still pointless.
 *
 * Registered as a container singleton, so the memo lives exactly as long as one
 * request (and one test). It is **flushed by the actions that change a
 * definition** rather than expiring on a timer: a field added, hidden or
 * reordered must show up on the very next render, not eventually.
 */
class CustomFieldSchema
{
    /**
     * @var array<string, Collection<int, CustomField>>
     */
    private array $byModule = [];

    /**
     * The active fields on a module, in order.
     *
     * Only active ones: this is what drives the form, the columns and the
     * filters, and a hidden field belongs in none of them. Its stored answers
     * are still readable through the record — see HasCustomFields.
     *
     * @return Collection<int, CustomField>
     */
    public function forModule(string $module): Collection
    {
        if (! CustomFieldRegistry::has($module)) {
            /** @var Collection<int, CustomField> */
            return collect();
        }

        return $this->byModule[$module] ??= CustomField::query()
            ->forModule($module)
            ->active()
            ->ordered()
            ->get();
    }

    /**
     * One active field on a module, by its own key.
     */
    public function find(string $module, string $key): ?CustomField
    {
        return $this->forModule($module)->firstWhere('key', $key);
    }

    public function flush(?string $module = null): void
    {
        if ($module === null) {
            $this->byModule = [];

            return;
        }

        unset($this->byModule[$module]);
    }
}
