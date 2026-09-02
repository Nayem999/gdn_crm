---
paths:
  - 'app/Domain/Access/**'
---

# Access

## PermissionCatalogue is the only source of grantable permissions
Every permission must be declared in App\Domain\Access\PermissionCatalogue. It drives the roles matrix UI and RolesAndPermissionsSeeder, and submitted permissions are filtered through `PermissionCatalogue::only()` so a tampered request can't grant something undeclared.

When a new module lands: add its group to the catalogue (permissions must be prefixed with the group key, e.g. `leads.view` under `leads`), then re-run `php artisan db:seed --class=RolesAndPermissionsSeeder` so the protected Super Admin role picks them up. A test in tests/Feature/Access/PermissionCatalogueTest.php scans every policy for `can('...')` strings and fails if one is missing from the catalogue.

Do NOT add a `Gate::before` super-admin bypass. Super Admin works by holding every permission, so business rules that deny regardless of permission still apply — e.g. UserPolicy::delete refusing self-deletion. A bypass would silently defeat those.

The Super Admin role is protected from edit/delete in RolePolicy and again in Update/DeleteRoleAction. A role still assigned to users cannot be deleted.

Create roles/permissions via `Role::query()->create()` / `Permission::query()->firstOrCreate()` (see PermissionResolver), not spatie's static `create()`/`findOrCreate()`, whose declared returns are the contracts and fail phpstan level 6. Set guard_name via `Guard::getDefaultName()` and flush with `PermissionRegistrar::forgetCachedPermissions()`.
