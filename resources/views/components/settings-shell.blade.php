@props(['heading', 'description' => null, 'active' => null, 'activeParams' => []])

@php
    use App\Domain\Shared\UI\NavIconPalette;

    $sections = \App\Domain\Settings\SettingsNavigation::for(auth()->user());

    // The active item comes from the caller, not the request: during a Livewire
    // update the request is POST /livewire/update, so anything derived from the
    // current URL would drop the highlight after the first interaction.
    $activeRoute = $active ?? request()->route()?->getName();
    $activeUrl = $activeRoute === null
        ? null
        : route($activeRoute, $activeParams ?: (request()->route()?->parameters() ?? []));
@endphp

<div class="flex flex-col gap-6 lg:flex-row lg:gap-8">
    <nav class="lg:w-60 lg:shrink-0" aria-label="Settings">
        {{-- A horizontal scroller on small screens, a sidebar from lg up. --}}
        <div class="flex gap-4 overflow-x-auto pb-2 lg:flex-col lg:gap-5 lg:overflow-visible lg:pb-0">
            @foreach ($sections as $section)
                <div class="shrink-0">
                    <p class="mb-1.5 hidden text-xs font-semibold uppercase tracking-wide text-muted-foreground lg:block">
                        {{ $section['label'] }}
                    </p>

                    <ul class="flex gap-1 lg:flex-col">
                        @foreach ($section['items'] as $item)
                            @php
                                $url = $item['params'] === [] ? route($item['route']) : route($item['route'], $item['params']);
                                $isActive = $activeUrl !== null && $activeUrl === $url;
                            @endphp

                            <li>
                                <a
                                    href="{{ $url }}"
                                    wire:navigate
                                    @class([
                                        'flex items-center gap-2.5 whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        'bg-muted text-foreground' => $isActive,
                                        'text-muted-foreground hover:bg-muted/60 hover:text-foreground' => ! $isActive,
                                    ])
                                    @if ($isActive) aria-current="page" @endif
                                >
                                    {{-- The same colour the icon wears in the
                                         main sidebar, from the same map, so
                                         Company under here and Accounts up
                                         there are recognisably the same
                                         building. --}}
                                    <x-icon
                                        :name="'lucide-' . $item['icon']"
                                        @class(['h-4 w-4 shrink-0', NavIconPalette::onPage($item['icon'])])
                                    />
                                    {{ $item['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </nav>

    <div class="min-w-0 flex-1">
        <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-foreground">{{ $heading }}</h1>

                @if ($description)
                    <p class="mt-1 text-sm text-muted-foreground">{{ $description }}</p>
                @endif
            </div>

            @if (isset($actions))
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endif
        </div>

        {{ $slot }}
    </div>
</div>
