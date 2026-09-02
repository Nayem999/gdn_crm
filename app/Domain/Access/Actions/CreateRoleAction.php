<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\DTOs\RoleData;
use App\Domain\Access\PermissionResolver;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CreateRoleAction
{
    public function __invoke(RoleData $data): Role
    {
        return DB::transaction(function () use ($data) {
            // Built through the query builder rather than Role::create(), whose
            // declared return is the interface rather than the model. Uniqueness
            // is already covered by the form rules and the (name, guard_name)
            // unique index, and the permission cache is flushed below.
            $role = Role::query()->create([
                'name' => $data->name,
                'guard_name' => Guard::getDefaultName(Role::class),
                'data_access_level' => $data->dataAccessLevel->value,
            ]);

            $role->syncPermissions(PermissionResolver::models($data->permissions));

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role;
        });
    }
}
