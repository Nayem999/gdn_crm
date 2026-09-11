<?php

namespace App\Domain\CustomFields\Actions;

use App\Domain\CustomFields\Models\CustomField;

/**
 * Removes a field definition and every answer given to it.
 *
 * The values go with it — the foreign key cascades — which is why the screen
 * says how many there are before asking. Switching a field off with `is_active`
 * is the reversible option, and it is what the UI offers first.
 */
class DeleteCustomFieldAction
{
    public function __invoke(CustomField $field): void
    {
        $field->delete();
    }
}
