@php
    // Modules gain a route as their phase lands; the rest stay inert
    // placeholders rather than pretending to be links.
    $navigation = [
        ['label' => 'Dashboard', 'icon' => 'layout-dashboard', 'route' => 'dashboard'],
        ['label' => 'Leads', 'icon' => 'target', 'route' => 'leads.index', 'permission' => 'leads.view'],
        ['label' => 'Contacts', 'icon' => 'contact', 'route' => 'contacts.index', 'permission' => 'contacts.view'],
        ['label' => 'Accounts', 'icon' => 'building-2', 'route' => 'accounts.index', 'permission' => 'accounts.view'],
        ['label' => 'Deals', 'icon' => 'handshake', 'route' => 'deals.index', 'permission' => 'deals.view'],
        ['label' => 'Activities', 'icon' => 'calendar-clock', 'route' => 'activities.index', 'permission' => 'activities.view'],
        ['label' => 'Calendar', 'icon' => 'calendar-days', 'route' => 'calendar', 'permission' => 'activities.view'],
        ['label' => 'Products', 'icon' => 'package', 'route' => 'products.index', 'permission' => 'products.view'],
        ['label' => 'Quotes', 'icon' => 'file-text', 'route' => 'quotes.index', 'permission' => 'quotes.view'],
        ['label' => 'Support', 'icon' => 'life-buoy', 'route' => 'tickets.index', 'permission' => 'tickets.view'],
        ['label' => 'Knowledge', 'icon' => 'book-open', 'route' => 'knowledge.index', 'permission' => 'knowledge.view'],
        ['label' => 'Reports', 'icon' => 'bar-chart-3', 'route' => 'reports.index', 'permission' => 'reports.view'],
        ['label' => 'Automation', 'icon' => 'zap', 'route' => 'workflows.index', 'permission' => 'workflows.view'],
    ];

    // Modules an administrator added at runtime, appended after the built-in
    // ones. Read through the memoised registry, so this costs one query per
    // request however many there are.
    if (auth()->user()?->can('custom-modules.view')) {
        foreach (app(\App\Domain\CustomModules\CustomModuleRegistry::class)->all() as $custom) {
            $navigation[] = [
                'label' => $custom->plural_name,
                'icon' => $custom->icon,
                'route' => 'custom-modules.index',
                'params' => ['module' => $custom->moduleKey()],
            ];
        }
    }
@endphp

<div
    x-show="sidebarOpen"
    x-cloak
    x-transition:enter="transition-opacity ease-out duration-200"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition-opacity ease-in duration-150"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    class="fixed inset-0 z-40 bg-slate-950/60 lg:hidden"
    @click="sidebarOpen = false"
    aria-hidden="true"
></div>

<aside
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    class="fixed inset-y-0 left-0 z-50 flex w-sidebar transform flex-col bg-sidebar text-sidebar-foreground transition-transform duration-200 ease-in-out lg:static lg:translate-x-0"
    aria-label="Primary"
>
    <div class="flex h-topbar shrink-0 items-center gap-3 border-b border-sidebar-border px-6">
        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-accent text-accent-foreground">
            <x-lucide-building-2 class="h-5 w-5" aria-hidden="true" />
        </span>
        <span class="text-base font-semibold text-white">{{ config('app.name') }}</span>
    </div>

    <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
        @foreach ($navigation as $item)
            @continue(isset($item['permission']) && ! auth()->user()?->can($item['permission']))

            @php
                // Generated modules pass route parameters; the built-in ones
                // take none.
                $url = isset($item['route']) ? route($item['route'], $item['params'] ?? []) : null;
                $active = isset($item['route']) && request()->routeIs(str($item['route'])->before('.')->value() . '*')
                    && $item['route'] !== 'dashboard';

                // Several generated modules share one route name, so the
                // highlight has to compare the key too, or they all light up
                // together.
                if ($active && isset($item['params']['module'])) {
                    $active = request()->route('module') === $item['params']['module'];
                }
            @endphp

            <a
                href="{{ $url ?? '#' }}"
                @if ($url) wire:navigate @endif
                @class([
                    'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-sidebar-active hover:text-white',
                    'bg-sidebar-active text-white' => $active,
                    'text-sidebar-foreground' => ! $active,
                    // Nothing to click yet: say so rather than offering a dead link.
                    'cursor-default opacity-60' => $url === null,
                ])
                @if ($url === null) aria-disabled="true" title="Coming in a later phase" @endif
                @if ($active) aria-current="page" @endif
            >
                <x-dynamic-component :component="'lucide-' . $item['icon']" class="h-5 w-5 shrink-0 text-slate-400 group-hover:text-white" aria-hidden="true" />
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>

    @php
        // Land on the first settings page this user can actually open, rather
        // than always Company and a 403 for anyone without company.view.
        $settingsUrl = \App\Domain\Settings\SettingsNavigation::landingRouteFor(auth()->user());
    @endphp

    @if ($settingsUrl)
    <div class="border-t border-sidebar-border px-3 py-4">
        <a
            href="{{ $settingsUrl }}"
            @class([
                'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-sidebar-active hover:text-white',
                'bg-sidebar-active text-white' => request()->routeIs('settings.*'),
                'text-sidebar-foreground' => ! request()->routeIs('settings.*'),
            ])
        >
            <x-lucide-settings class="h-5 w-5 shrink-0 text-slate-400 group-hover:text-white" aria-hidden="true" />
            Settings
        </a>
    </div>
    @endif
</aside>
