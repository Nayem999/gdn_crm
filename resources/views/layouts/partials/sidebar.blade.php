@php
    use App\Domain\Shared\UI\NavIconPalette;
    use Illuminate\Support\Str;

    // Modules gain a route as their phase lands; the rest stay inert
    // placeholders rather than pretending to be links.
    //
    // A `section` entry is a heading for the rows beneath it, and it is shown
    // only when at least one of them is. A header standing over nothing tells
    // somebody a feature exists and then refuses to say where.
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

    ];

    // Modules an administrator added at runtime, added after the built-in ones
    // and **before** the headed sections below: appended at the very end they
    // would sit under whichever heading happened to be last, which would file a
    // module called Projects under "Insight". Read through the memoised
    // registry, so this costs one query per request however many there are.
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

    // Everything Meta touches, gathered where somebody would look for it.
    // Answering a customer on WhatsApp and reading what an advertisement cost
    // are the same person's morning, and the Meta screens were previously
    // reachable only by going through Settings — which is where an integration
    // is *configured*, not where it is used.
    $navigation = array_merge($navigation, [
        ['section' => 'Marketing & social'],
        ['label' => 'Campaigns', 'icon' => 'megaphone', 'route' => 'campaigns.index', 'permission' => 'campaigns.view'],
        // Two rows onto one screen, pre-filtered. Not two screens: the reply
        // window, the templates, the assignment and the lead creation are the
        // same rules whichever channel a customer used, and a second copy of
        // them is a second place for the same bug to be fixed once.
        ['label' => 'WhatsApp chat', 'icon' => 'message-circle', 'route' => 'social.inbox', 'query' => ['channel' => 'whatsapp'], 'permission' => 'social.inbox.view'],
        ['label' => 'Messenger chat', 'icon' => 'messages-square', 'route' => 'social.inbox', 'query' => ['channel' => 'messenger'], 'permission' => 'social.inbox.view'],
        ['label' => 'Ad performance', 'icon' => 'trending-up', 'route' => 'settings.meta.performance', 'permission' => 'meta.campaigns.view'],
        ['label' => 'Meta ads', 'icon' => 'badge-dollar-sign', 'route' => 'settings.meta.campaigns', 'permission' => 'meta.campaigns.view'],
        ['label' => 'Meta conversions', 'icon' => 'target', 'route' => 'settings.meta.conversions', 'permission' => 'meta.view'],

        ['section' => 'Insight'],
        ['label' => 'Reports', 'icon' => 'bar-chart-3', 'route' => 'reports.index', 'permission' => 'reports.view'],
        ['label' => 'Automation', 'icon' => 'zap', 'route' => 'workflows.index', 'permission' => 'workflows.view'],
    ]);

    // Which headings have something under them. Worked out once here rather
    // than peered at during the loop, because "is any later row visible" is a
    // question about the list, not about the row being drawn.
    $visibleSections = [];
    $openSection = null;

    foreach ($navigation as $entry) {
        if (isset($entry['section'])) {
            $openSection = $entry['section'];

            continue;
        }

        if ($openSection !== null && ! (isset($entry['permission']) && ! auth()->user()?->can($entry['permission']))) {
            $visibleSections[$openSection] = true;
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
            @if (isset($item['section']))
                @continue(! isset($visibleSections[$item['section']]))

                <p class="px-3 pb-1 pt-5 text-xs font-semibold uppercase tracking-wider text-slate-500">
                    {{ $item['section'] }}
                </p>

                @continue
            @endif

            @continue(isset($item['permission']) && ! auth()->user()?->can($item['permission']))

            @php
                // Generated modules pass route parameters; the built-in ones
                // take none.
                $url = isset($item['route'])
                    ? route($item['route'], array_merge($item['params'] ?? [], $item['query'] ?? []))
                    : null;

                // Matched on the whole route name for anything under settings,
                // and on the module prefix otherwise. Without the distinction
                // every Meta row in this list lights up on every settings page,
                // because they all begin "settings".
                $pattern = isset($item['route']) && Str::startsWith($item['route'], 'settings.')
                    ? $item['route']
                    : Str::before((string) ($item['route'] ?? ''), '.').'*';

                $active = isset($item['route'])
                    && request()->routeIs($pattern)
                    && $item['route'] !== 'dashboard';

                // Several generated modules share one route name, so the
                // highlight has to compare the key too, or they all light up
                // together.
                if ($active && isset($item['params']['module'])) {
                    $active = request()->route('module') === $item['params']['module'];
                }

                // Same for two rows onto one screen: without this both channels
                // are highlighted whichever one is being read.
                if ($active && isset($item['query'])) {
                    foreach ($item['query'] as $key => $value) {
                        $active = $active && request()->query($key) === $value;
                    }
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
                {{-- Its own colour, and it keeps it on hover and while active:
                     the colour is how a row is recognised before the label is
                     read, so washing it to white under the pointer would take
                     the landmark away exactly when it is being used. --}}
                <x-dynamic-component
                    :component="'lucide-' . $item['icon']"
                    @class(['h-5 w-5 shrink-0', NavIconPalette::onDark($item['icon'])])
                    aria-hidden="true"
                />
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>

    @php
        // Land on the first settings page this user can actually open, rather
        // than always Company and a 403 for anyone without company.view.
        $settingsUrl = \App\Domain\Settings\SettingsNavigation::landingRouteFor(auth()->user());
    @endphp

    <div class="space-y-1 border-t border-sidebar-border px-3 py-4">
        @if ($settingsUrl)
            <a
                href="{{ $settingsUrl }}"
                @class([
                    'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-sidebar-active hover:text-white',
                    'bg-sidebar-active text-white' => request()->routeIs('settings.*'),
                    'text-sidebar-foreground' => ! request()->routeIs('settings.*'),
                ])
            >
                <x-lucide-settings @class(['h-5 w-5 shrink-0', NavIconPalette::onDark('settings')]) aria-hidden="true" />
                Settings
            </a>
        @endif

        {{-- The manual. Ungated, because the page itself is: it is the same
             documentation a stranger can read at /guide, and hiding it from
             somebody holding no permissions would hide the one thing that
             explains why they hold none.

             A plain link rather than wire:navigate, and a new tab: /guide is a
             standalone page outside this shell, and somebody checking how a
             thing works should come back to the screen they left. --}}
        <a
            href="{{ route('guide') }}"
            target="_blank"
            rel="noopener"
            class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-sidebar-foreground transition-colors hover:bg-sidebar-active hover:text-white"
        >
            {{-- Not book-open: the Knowledge module above already wears it,
                 and two identical icons in one sidebar is two wrong guesses. --}}
            <x-lucide-circle-help @class(['h-5 w-5 shrink-0', NavIconPalette::onDark('circle-help')]) aria-hidden="true" />
            Guide
            <x-lucide-external-link class="ml-auto h-3.5 w-3.5 shrink-0 text-slate-500 group-hover:text-slate-300" aria-hidden="true" />
            <span class="sr-only">(opens in a new tab)</span>
        </a>
    </div>
</aside>
