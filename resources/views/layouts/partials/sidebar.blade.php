@php
    $navigation = [
        ['label' => 'Dashboard', 'icon' => 'layout-dashboard'],
        ['label' => 'Leads', 'icon' => 'target'],
        ['label' => 'Contacts', 'icon' => 'users'],
        ['label' => 'Accounts', 'icon' => 'building-2'],
        ['label' => 'Deals', 'icon' => 'handshake'],
        ['label' => 'Activities', 'icon' => 'calendar-clock'],
        ['label' => 'Products', 'icon' => 'package'],
        ['label' => 'Quotes & Invoices', 'icon' => 'file-text'],
        ['label' => 'Support', 'icon' => 'life-buoy'],
        ['label' => 'Reports', 'icon' => 'bar-chart-3'],
        ['label' => 'Automation', 'icon' => 'zap'],
    ];
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
            <a
                href="#"
                class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-sidebar-foreground transition-colors hover:bg-sidebar-active hover:text-white"
            >
                <x-dynamic-component :component="'lucide-' . $item['icon']" class="h-5 w-5 shrink-0 text-slate-400 group-hover:text-white" aria-hidden="true" />
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>

    <div class="border-t border-sidebar-border px-3 py-4">
        <a
            href="#"
            class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-sidebar-foreground transition-colors hover:bg-sidebar-active hover:text-white"
        >
            <x-lucide-settings class="h-5 w-5 shrink-0 text-slate-400 group-hover:text-white" aria-hidden="true" />
            Settings
        </a>
    </div>
</aside>
