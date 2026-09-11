<?php

namespace App\Domain\CustomFields\Actions;

use App\Domain\CustomFields\CustomFieldSchema;
use App\Domain\CustomFields\Models\CustomField;

/**
 * Shows or hides a field on the form, keeping the answers it already holds.
 *
 * An action rather than a line in the component, so the schema memo is flushed
 * on every path that changes a definition. A component writing `is_active`
 * itself would leave the list, the column manager and the filter builder
 * showing the old answer until the next request.
 */
class ToggleCustomFieldAction
{
    /**
     * @return bool the field's new active state
     */
    public function __invoke(CustomField $field): bool
    {
        $field->forceFill(['is_active' => ! $field->is_active])->save();

        app(CustomFieldSchema::class)->flush();

        return (bool) $field->is_active;
    }
}
