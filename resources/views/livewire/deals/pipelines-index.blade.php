<div>
    <x-settings-shell heading="Pipelines" description="The routes a deal can be worked along, and the stages on each." active="settings.pipelines">

    <div
        x-data="{ message: '', tone: 'success' }"
        x-on:notify.window="tone = $event.detail.type === 'error' ? 'error' : 'success'; message = $event.detail.message; setTimeout(() => message = '', 5000)"
        x-show="message"
        x-cloak
        class="mb-4"
    >
        <template x-if="tone === 'error'">
            <x-alert variant="error"><span x-text="message"></span></x-alert>
        </template>
        <template x-if="tone !== 'error'">
            <x-alert variant="success"><span x-text="message"></span></x-alert>
        </template>
    </div>

    @if (session('status'))
        <x-alert variant="success" class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if ($pipelines->isEmpty())
        <x-empty-state
            icon="git-branch"
            heading="No pipelines yet"
            description="A pipeline is the route a deal is worked along. Create one to get started."
        />
    @else
        <p class="mb-3 text-xs text-muted-foreground">
            Drag a pipeline by its handle to change the order they are offered in.
        </p>

        <ul
            class="space-y-3"
            x-data="sortableList({ method: 'reorder' })"
            wire:key="pipelines-{{ $pipelines->pluck('id')->join('-') }}"
        >
            @foreach ($pipelines as $pipeline)
                <li
                    class="rounded-xl border border-border bg-card p-4"
                    data-sortable-item
                    data-sortable-id="{{ $pipeline->id }}"
                    wire:key="pipeline-{{ $pipeline->id }}"
                >
                    <div class="flex flex-wrap items-start gap-3">
                        <button
                            type="button"
                            data-sortable-handle
                            class="mt-0.5 flex h-8 w-8 shrink-0 cursor-grab items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground"
                            aria-label="Reorder {{ $pipeline->name }}"
                        >
                            <x-icon name="lucide-grip-vertical" class="h-4 w-4" />
                        </button>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm font-semibold text-foreground">{{ $pipeline->name }}</h3>

                                @if ($pipeline->is_default)
                                    <x-status-chip color="emerald" dot>Default</x-status-chip>
                                @endif

                                <span class="text-xs text-muted-foreground">
                                    {{ $pipeline->deals_count }} {{ Str::plural('deal', $pipeline->deals_count) }}
                                </span>
                            </div>

                            @if ($pipeline->description)
                                <p class="mt-0.5 text-sm text-muted-foreground">{{ $pipeline->description }}</p>
                            @endif

                            {{-- The stages in order, which is what the pipeline
                                 actually is. --}}
                            <div class="mt-3 flex flex-wrap items-center gap-1.5">
                                @foreach ($pipeline->stages as $stage)
                                    <span class="inline-flex items-center gap-1.5 rounded-full border border-border px-2 py-0.5 text-xs">
                                        <span class="h-1.5 w-1.5 rounded-full {{ \App\Domain\Shared\UI\ChipPalette::dotClasses($stage->color) }}" aria-hidden="true"></span>
                                        <span class="text-foreground">{{ $stage->name }}</span>
                                        <span class="text-muted-foreground">{{ $stage->probability }}%</span>
                                    </span>

                                    @unless ($loop->last)
                                        <x-icon name="lucide-chevron-right" class="h-3 w-3 text-muted-foreground" />
                                    @endunless
                                @endforeach
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            @unless ($pipeline->is_default)
                                @can('update', $pipeline)
                                    <button
                                        type="button"
                                        wire:click="makeDefault({{ $pipeline->id }})"
                                        wire:loading.attr="disabled"
                                        class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted-foreground hover:bg-muted hover:text-foreground"
                                    >Make default</button>
                                @endcan
                            @endunless

                            @can('update', $pipeline)
                                <a
                                    href="{{ route('settings.pipelines.edit', $pipeline) }}"
                                    wire:navigate
                                    class="flex h-8 w-8 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground"
                                    aria-label="Edit {{ $pipeline->name }}"
                                >
                                    <x-icon name="lucide-pencil" class="h-4 w-4" />
                                </a>
                            @endcan

                            @can('delete', $pipeline)
                                <button
                                    type="button"
                                    wire:click="delete({{ $pipeline->id }})"
                                    wire:confirm="Remove the {{ $pipeline->name }} pipeline?"
                                    wire:loading.attr="disabled"
                                    class="flex h-8 w-8 items-center justify-center rounded-lg text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                    aria-label="Remove {{ $pipeline->name }}"
                                >
                                    <x-icon name="lucide-trash-2" class="h-4 w-4" />
                                </button>
                            @else
                                {{-- Say why rather than showing a button that
                                     would only be refused. --}}
                                @if ($pipeline->deletionBlocker())
                                    <span class="max-w-40 text-right text-xs text-muted-foreground" title="{{ $pipeline->deletionBlocker() }}">
                                        {{ $pipeline->is_default ? 'Default' : 'In use' }}
                                    </span>
                                @endif
                            @endcan
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    {{-- Named slots go last: Blade leaks an output buffer when one precedes
         the default content. See .ai/rules/views.md. --}}
    <x-slot:actions>
        @can('create', App\Domain\Deals\Models\Pipeline::class)
            <a href="{{ route('settings.pipelines.create') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90">
                <x-icon name="lucide-plus" class="h-4 w-4" />
                Add pipeline
            </a>
        @endcan
    </x-slot:actions>
    </x-settings-shell>
</div>
