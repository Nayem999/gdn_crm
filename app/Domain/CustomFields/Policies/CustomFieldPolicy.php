<?php

namespace App\Domain\CustomFields\Policies;

use App\Domain\CustomFields\Models\CustomField;
use App\Models\User;

/**
 * Defining a field changes what every record in a module can hold, so it is an
 * administrative act rather than a per-record one: there is no access level
 * here, only the permission.
 *
 * Answering a custom field is governed by the record's own policy — somebody
 * who may update a lead may fill in its custom fields, and needs nothing extra.
 */
class CustomFieldPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('custom-fields.view');
    }

    public function view(User $user, CustomField $field): bool
    {
        return $user->can('custom-fields.view');
    }

    public function create(User $user): bool
    {
        return $user->can('custom-fields.manage');
    }

    public function update(User $user, CustomField $field): bool
    {
        return $user->can('custom-fields.manage');
    }

    public function delete(User $user, CustomField $field): bool
    {
        return $user->can('custom-fields.manage');
    }
}
