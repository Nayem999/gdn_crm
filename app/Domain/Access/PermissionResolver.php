<?php

namespace App\Domain\Access;

use Spatie\Permission\Guard;
use Spatie\Permission\Models\Permission;

final class PermissionResolver
{
    /**
     * Turn permission names into models, dropping anything outside the
     * catalogue so a tampered request cannot grant an unknown ability.
     *
     * Uses the query builder rather than Permission::findOrCreate(), whose
     * declared return type is the interface rather than the model.
     *
     * @param  array<int, string>  $names
     * @return array<int, Permission>
     */
    public static function models(array $names): array
    {
        $guard = Guard::getDefaultName(Permission::class);

        return array_map(
            fn (string $name) => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => $guard]),
            PermissionCatalogue::only($names)
        );
    }
}
