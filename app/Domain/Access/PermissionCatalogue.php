<?php

namespace App\Domain\Access;

/**
 * The canonical list of permissions the application enforces.
 *
 * This is the source of truth for the roles matrix and for the seeder, so every
 * checkbox on that screen maps to a permission some policy actually checks. When
 * a new module lands, add its group here and re-run RolesAndPermissionsSeeder.
 */
final class PermissionCatalogue
{
    /**
     * A role that always holds every permission, kept in sync by the seeder and
     * protected from editing so the last administrator cannot be locked out.
     */
    public const SUPER_ADMIN_ROLE = 'Super Admin';

    /**
     * @return array<string, array{label: string, icon: string, permissions: array<string, string>}>
     */
    public static function groups(): array
    {
        return [
            'company' => [
                'label' => 'Company',
                'icon' => 'building-2',
                'permissions' => [
                    'company.view' => 'View the company profile',
                    'company.update' => 'Update the company profile',
                ],
            ],
            'users' => [
                'label' => 'Users',
                'icon' => 'users',
                'permissions' => [
                    'users.view' => 'View users',
                    'users.create' => 'Create users',
                    'users.update' => 'Update users',
                    'users.delete' => 'Remove users',
                    'users.invite' => 'Invite users by email',
                ],
            ],
            'teams' => [
                'label' => 'Teams',
                'icon' => 'network',
                'permissions' => [
                    'teams.view' => 'View teams',
                    'teams.create' => 'Create teams',
                    'teams.update' => 'Update teams and their membership',
                    'teams.delete' => 'Remove teams',
                ],
            ],
            'roles' => [
                'label' => 'Roles & permissions',
                'icon' => 'shield-check',
                'permissions' => [
                    'roles.view' => 'View roles',
                    'roles.create' => 'Create roles',
                    'roles.update' => 'Update roles, permissions and access levels',
                    'roles.delete' => 'Remove roles',
                ],
            ],
        ];
    }

    /**
     * Every permission name, flattened.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $permissions = [];

        foreach (self::groups() as $group) {
            foreach (array_keys($group['permissions']) as $permission) {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }

    public static function has(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    /**
     * Filter a submitted set down to permissions that actually exist, so a
     * tampered request cannot grant something outside the catalogue.
     *
     * @param  array<int, string>  $permissions
     * @return list<string>
     */
    public static function only(array $permissions): array
    {
        return array_values(array_filter(
            array_unique($permissions),
            fn (string $permission) => self::has($permission)
        ));
    }
}
