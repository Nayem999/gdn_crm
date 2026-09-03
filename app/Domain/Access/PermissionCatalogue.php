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
            'accounts' => [
                'label' => 'Accounts',
                'icon' => 'building-2',
                'permissions' => [
                    'accounts.view' => 'View accounts',
                    'accounts.create' => 'Create accounts',
                    'accounts.update' => 'Update accounts',
                    'accounts.delete' => 'Remove accounts',
                    'accounts.export' => 'Export accounts',
                ],
            ],
            'leads' => [
                'label' => 'Leads',
                'icon' => 'target',
                'permissions' => [
                    'leads.view' => 'View leads',
                    'leads.create' => 'Capture leads',
                    'leads.update' => 'Update leads and move their status',
                    'leads.assign' => 'Hand a lead to someone else',
                    'leads.delete' => 'Remove leads',
                    'leads.export' => 'Export leads',
                ],
            ],
            'contacts' => [
                'label' => 'Contacts',
                'icon' => 'contact',
                'permissions' => [
                    'contacts.view' => 'View contacts',
                    'contacts.create' => 'Create contacts',
                    'contacts.update' => 'Update contacts',
                    'contacts.delete' => 'Remove contacts',
                    'contacts.export' => 'Export contacts',
                ],
            ],
            'settings' => [
                'label' => 'Settings',
                'icon' => 'settings',
                'permissions' => [
                    'settings.view' => 'View application settings',
                    'settings.update' => 'Change application settings',
                    // Deliberately separate: someone can be trusted with a date
                    // format without being handed integration credentials.
                    'settings.secrets' => 'Read and replace stored credentials',
                ],
            ],
            'notifications' => [
                'label' => 'Notifications',
                'icon' => 'bell',
                'permissions' => [
                    'notifications.view' => 'View the notification matrix, templates and log',
                    'notifications.update' => 'Change the notification matrix and templates',
                ],
            ],
            'audit' => [
                'label' => 'Audit log',
                'icon' => 'scroll-text',
                'permissions' => [
                    'audit.view' => 'View the audit log',
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
