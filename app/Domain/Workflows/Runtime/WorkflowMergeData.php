<?php

namespace App\Domain\Workflows\Runtime;

use App\Domain\CustomFields\CustomFieldRegistry;
use App\Domain\Workflows\WorkflowModules;
use Illuminate\Database\Eloquent\Model;

/**
 * The values a workflow's templates can merge in.
 *
 * Built from the module's **own declared field set**, not `toArray()`. That is
 * the same allowlist the list screen, the export and the filter builder use, so
 * a column somebody adds to a table does not silently become something a
 * workflow can email to a customer.
 *
 * Rendered by `TemplateRenderer`, which replaces `{{ record.first_name }}` by
 * scanning for placeholders — never by compiling. An admin-authored template
 * cannot become executable, and neither can anything in the merged data.
 */
class WorkflowMergeData
{
    /**
     * @param  array<string, mixed>  $trigger
     * @return array<string, mixed>
     */
    public static function for(string $module, ?Model $record, array $trigger = []): array
    {
        $data = [
            'app' => ['name' => config('app.name')],
            'module' => ['label' => WorkflowModules::label($module)],
        ];

        if ($record === null) {
            return $data;
        }

        $fields = [];

        foreach (WorkflowModules::fields($module) as $key => $field) {
            // A custom field's answer is not a column; read it the way 4.1
            // stores it.
            $fields[$key] = $field->isCustomField()
                ? self::customValue($record, $key)
                : $record->getAttribute($field->column());
        }

        $data['record'] = [
            ...$fields,
            'id' => $record->getKey(),
            'label' => CustomFieldRegistry::recordLabel($record),
        ];

        // The old values, so a template can say what a status moved from.
        $changed = $trigger['changed'] ?? [];

        if (is_array($changed)) {
            foreach ($changed as $key => $change) {
                $data['was'][$key] = is_array($change) ? ($change['from'] ?? null) : null;
            }
        }

        return $data;
    }

    private static function customValue(Model $record, string $prefixedKey): mixed
    {
        if (! method_exists($record, 'customField')) {
            return null;
        }

        // Column keys are prefixed `cf_` so a custom field cannot shadow a real
        // column; the stored key has no prefix.
        return $record->customField((string) str($prefixedKey)->after('cf_'));
    }
}
