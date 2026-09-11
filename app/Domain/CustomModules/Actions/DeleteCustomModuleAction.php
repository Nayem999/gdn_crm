<?php

namespace App\Domain\CustomModules\Actions;

use App\Domain\CustomFields\CustomFieldSchema;
use App\Domain\CustomFields\Models\CustomField;
use App\Domain\CustomModules\CustomModuleRegistry;
use App\Domain\CustomModules\Models\CustomModule;
use Illuminate\Support\Facades\DB;

/**
 * Removes a module, its records and its field definitions.
 *
 * The records cascade off the foreign key. The **field definitions do not** —
 * `custom_fields.module` is a string, not a relation, so nothing in the
 * database would clear them and they would sit there describing a module that
 * no longer exists, turning up in the custom fields screen under a heading
 * nobody recognises. So they are deleted here, in the same transaction.
 *
 * Switching a module off with `is_active` is the reversible option, and it is
 * what the screen offers first.
 */
class DeleteCustomModuleAction
{
    public function __invoke(CustomModule $module): void
    {
        $moduleKey = $module->moduleKey();

        DB::transaction(function () use ($module, $moduleKey) {
            // Values cascade off custom_field_id when the definitions go.
            CustomField::query()->forModule($moduleKey)->delete();

            $module->delete();
        });

        app(CustomModuleRegistry::class)->flush();
        app(CustomFieldSchema::class)->flush($moduleKey);
    }
}
