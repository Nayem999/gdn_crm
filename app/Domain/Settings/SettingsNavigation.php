<?php

namespace App\Domain\Settings;

use App\Models\User;

/**
 * Everything reachable under Settings, in the order it should be listed.
 *
 * Registry groups appear alongside the screens that own their own tables
 * (Company, Users, Teams, Roles, Audit), so the navigation reflects what an
 * administrator sees rather than how the data happens to be stored. Each entry
 * declares the permission that gates it, and entries the viewer cannot reach are
 * left out rather than shown and then refused.
 */
final class SettingsNavigation
{
    /**
     * @return array<int, array{label: string, items: array<int, array{label: string, icon: string, route: string, params: array<string, string>, permission: string}>}>
     */
    public static function sections(): array
    {
        $groupItems = [];

        foreach (SettingsRegistry::groups() as $key => $group) {
            $groupItems[] = [
                'label' => $group['label'],
                'icon' => $group['icon'],
                'route' => 'settings.group',
                'params' => ['group' => $key],
                'permission' => 'settings.view',
            ];
        }

        return [
            [
                'label' => 'General',
                'items' => [
                    [
                        'label' => 'Company',
                        'icon' => 'building-2',
                        'route' => 'settings.company',
                        'params' => [],
                        'permission' => 'company.view',
                    ],
                    ...$groupItems,
                ],
            ],
            [
                'label' => 'Access',
                'items' => [
                    ['label' => 'Users', 'icon' => 'users', 'route' => 'settings.users', 'params' => [], 'permission' => 'users.view'],
                    ['label' => 'Teams', 'icon' => 'network', 'route' => 'settings.teams', 'params' => [], 'permission' => 'teams.view'],
                    ['label' => 'Roles & permissions', 'icon' => 'shield-check', 'route' => 'settings.roles', 'params' => [], 'permission' => 'roles.view'],
                ],
            ],
            [
                'label' => 'Modules',
                'items' => [
                    ['label' => 'Lead scoring', 'icon' => 'gauge', 'route' => 'settings.lead-scoring', 'params' => [], 'permission' => 'leads.scoring'],
                    ['label' => 'Lead capture forms', 'icon' => 'clipboard-list', 'route' => 'settings.lead-forms', 'params' => [], 'permission' => 'leads.forms'],
                    ['label' => 'Pipelines', 'icon' => 'git-branch', 'route' => 'settings.pipelines', 'params' => [], 'permission' => 'deals.pipelines'],
                    ['label' => 'Price books', 'icon' => 'tags', 'route' => 'settings.price-books', 'params' => [], 'permission' => 'products.view'],
                    ['label' => 'Custom fields', 'icon' => 'sliders-horizontal', 'route' => 'settings.custom-fields', 'params' => [], 'permission' => 'custom-fields.view'],
                    ['label' => 'Custom modules', 'icon' => 'box', 'route' => 'settings.custom-modules', 'params' => [], 'permission' => 'custom-modules.configure'],
                ],
            ],
            [
                'label' => 'System',
                'items' => [
                    ['label' => 'Notification rules', 'icon' => 'bell-ring', 'route' => 'settings.notifications', 'params' => [], 'permission' => 'notifications.view'],
                    ['label' => 'Audit log', 'icon' => 'scroll-text', 'route' => 'settings.audit', 'params' => [], 'permission' => 'audit.view'],
                ],
            ],
        ];
    }

    /**
     * The same list, with anything this user cannot reach removed.
     *
     * @return array<int, array{label: string, items: array<int, array{label: string, icon: string, route: string, params: array<string, string>, permission: string}>}>
     */
    public static function for(User $user): array
    {
        $sections = [];

        foreach (self::sections() as $section) {
            $items = array_values(array_filter(
                $section['items'],
                fn (array $item) => $user->can($item['permission'])
            ));

            if ($items !== []) {
                $sections[] = ['label' => $section['label'], 'items' => $items];
            }
        }

        return $sections;
    }

    /**
     * Where to send someone who asks for "Settings" with no particular page in
     * mind — the first thing they are actually allowed to open.
     */
    public static function landingRouteFor(User $user): ?string
    {
        foreach (self::for($user) as $section) {
            foreach ($section['items'] as $item) {
                return $item['params'] === []
                    ? route($item['route'])
                    : route($item['route'], $item['params']);
            }
        }

        return null;
    }
}
