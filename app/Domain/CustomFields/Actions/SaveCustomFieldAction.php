<?php

namespace App\Domain\CustomFields\Actions;

use App\Domain\CustomFields\CustomFieldRegistry;
use App\Domain\CustomFields\CustomFieldSchema;
use App\Domain\CustomFields\DTOs\CustomFieldData;
use App\Domain\CustomFields\Models\CustomField;
use RuntimeException;

/**
 * Creates or updates one field definition.
 *
 * The only writer of `key` and of `module`, and it writes each exactly once:
 *
 * - **key** is derived from the label on create and never touched again. Saved
 *   filters, import mappings and export columns all refer to a field by key, so
 *   reassigning one would silently repoint every one of them.
 * - **module** is set on create and refused afterwards. Moving a field between
 *   modules would strand its values on records of the wrong type — the rows
 *   would still be there, matched by a `customizable_type` that no longer
 *   corresponds to any field.
 *
 * Both are enforced by leaving them out of the DTO's `toAttributes()` rather
 * than by checking them on each path.
 */
class SaveCustomFieldAction
{
    /**
     * @throws RuntimeException when the module is not one the registry lists
     */
    public function __invoke(CustomFieldData $data, ?CustomField $field = null): CustomField
    {
        if ($field === null) {
            return $this->create($data);
        }

        $field->forceFill($data->toAttributes())->save();

        // The definitions are memoised per request (CustomFieldSchema), and a
        // field added, hidden or reordered has to show up on the very next
        // render rather than eventually.
        app(CustomFieldSchema::class)->flush();

        return $field->fresh() ?? $field;
    }

    private function create(CustomFieldData $data): CustomField
    {
        if (! CustomFieldRegistry::has($data->module)) {
            throw new RuntimeException('That is not a module custom fields can be added to.');
        }

        $taken = CustomField::query()
            ->forModule($data->module)
            ->pluck('key')
            ->all();

        $field = new CustomField;

        $field->forceFill([
            ...$data->toAttributes(),
            'module' => $data->module,
            'key' => CustomField::keyFrom($data->label, $taken),
            // Appended rather than inserted, so adding a field does not reorder
            // the form for everybody already using it.
            'position' => (int) CustomField::query()->forModule($data->module)->max('position') + 1,
        ])->save();

        // The definitions are memoised per request (CustomFieldSchema), and a
        // field added, hidden or reordered has to show up on the very next
        // render rather than eventually.
        app(CustomFieldSchema::class)->flush();

        return $field->fresh() ?? $field;
    }
}
