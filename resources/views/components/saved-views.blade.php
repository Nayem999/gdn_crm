@props(['view'])

@php
    $views = $view->savedViews();
    $current = $view->currentSavedView();
    $defaultId = $view->defaultSavedViewId();
    $viewer = auth()->user();
@endphp

<div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
    <button
        type="button"
        x-on:click="open = ! open"
        @class([
            'inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition-colors',
            'border-accent bg-accent/10 text-accent' => $current !== null,
            'border-border text-foreground hover:bg-muted' => $current === null,
        ])
        aria-haspopup="true"
        :aria-expanded="open ? 'true' : 'false'"
    >
        <x-icon name="lucide-bookmark" class="h-4 w-4" />
        <span class="max-w-[10rem] truncate">{{ $current?->name ?? 'Views' }}</span>

        @if ($view->savedViewHasChanges())
            {{-- The screen has drifted from the view it was opened on, so say
                 so rather than keeping two truths quietly. --}}
            <span class="rounded-full bg-amber-100 px-1.5 text-[10px] font-semibold text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">
                Edited
            </span>
        @endif

        <x-icon name="lucide-chevron-down" class="h-3.5 w-3.5" />
    </button>

    <div
        x-show="open"
        x-cloak
        x-on:click.outside="open = false"
        {{-- Anchored right: the toolbar sits at the right edge of the list, and
             a left-anchored panel this wide runs off the viewport. Same reason
             the filter panel is. --}}
        class="absolute right-0 z-30 mt-2 w-72 rounded-xl border border-border bg-card p-2 shadow-lg"
    >
        @if ($views->isEmpty())
            <p class="px-2 py-3 text-sm text-muted-foreground">
                No saved views yet. Arrange the list how you want it, then save it.
            </p>
        @else
            <ul class="max-h-64 space-y-0.5 overflow-y-auto">
                @foreach ($views as $saved)
                    <li wire:key="saved-view-{{ $saved->id }}" class="group flex items-center gap-1 rounded-lg px-1 hover:bg-muted">
                        <button
                            type="button"
                            wire:click="applySavedView({{ $saved->id }})"
                            x-on:click="open = false"
                            class="min-w-0 flex-1 py-1.5 text-left text-sm"
                        >
                            <span @class(['truncate', 'font-semibold text-accent' => $current?->id === $saved->id])>
                                {{ $saved->name }}
                            </span>

                            <span class="ml-1 text-xs text-muted-foreground">
                                @if ($saved->is_shared)
                                    &middot; shared by {{ $viewer && $saved->isOwnedBy($viewer) ? 'you' : $saved->owner?->name }}
                                @endif
                                @if ($defaultId === $saved->id)
                                    &middot; opens by default
                                @endif
                            </span>
                        </button>

                        <button
                            type="button"
                            wire:click="makeSavedViewDefault({{ $defaultId === $saved->id ? 'null' : $saved->id }})"
                            class="shrink-0 rounded p-1 text-muted-foreground opacity-0 transition-opacity hover:text-foreground group-hover:opacity-100"
                            title="{{ $defaultId === $saved->id ? 'Stop opening on this view' : 'Open this module on this view' }}"
                        >
                            <x-icon :name="$defaultId === $saved->id ? 'lucide-pin-off' : 'lucide-pin'" class="h-3.5 w-3.5" />
                        </button>

                        @if ($viewer && $saved->isOwnedBy($viewer))
                            <button
                                type="button"
                                wire:click="deleteSavedView({{ $saved->id }})"
                                wire:confirm="Remove {{ $saved->name }}?"
                                class="shrink-0 rounded p-1 text-muted-foreground opacity-0 transition-opacity hover:text-destructive group-hover:opacity-100"
                                title="Remove this view"
                            >
                                <x-icon name="lucide-trash-2" class="h-3.5 w-3.5" />
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="mt-2 space-y-1 border-t border-border pt-2">
            @if ($current !== null)
                @if ($viewer && $current->isOwnedBy($viewer) && $view->savedViewHasChanges())
                    <button
                        type="button"
                        wire:click="updateSavedView"
                        x-on:click="open = false"
                        class="w-full rounded-lg px-2 py-1.5 text-left text-sm font-medium text-foreground hover:bg-muted"
                    >Update &ldquo;{{ $current->name }}&rdquo;</button>
                @endif

                <button
                    type="button"
                    wire:click="clearSavedView"
                    x-on:click="open = false"
                    class="w-full rounded-lg px-2 py-1.5 text-left text-sm text-muted-foreground hover:bg-muted hover:text-foreground"
                >Stop using this view</button>
            @endif

            <button
                type="button"
                wire:click="startSavingView"
                x-on:click="open = false"
                class="w-full rounded-lg px-2 py-1.5 text-left text-sm font-medium text-accent hover:bg-muted"
            >Save this arrangement&hellip;</button>
        </div>
    </div>

    {{-- The save dialog. --}}
    @if ($view->savingView)
        <div class="fixed inset-0 z-50 flex items-start justify-center bg-slate-950/60 p-4" role="dialog" aria-modal="true">
            <form wire:submit="saveView" class="mt-24 w-full max-w-md rounded-xl border border-border bg-card p-5 shadow-lg">
                <h2 class="text-base font-semibold text-foreground">Save this arrangement</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    Its filters, columns, sort and view mode. Saving over a name you already
                    used replaces that view.
                </p>

                <div class="mt-4">
                    <x-form.label for="saved-view-name" required>Name</x-form.label>
                    <x-form.input id="saved-view-name" wire:model="savedViewName" placeholder="My open leads" class="mt-1" />
                    <x-form.error for="savedViewName" class="mt-1" />
                </div>

                @if ($view->canShareSavedViews())
                    <label class="mt-4 flex items-start gap-2 text-sm text-foreground">
                        <input type="checkbox" wire:model="savedViewShared" class="mt-0.5 rounded border-border text-accent focus:ring-accent/40">
                        <span>
                            Share with everyone
                            <span class="block text-xs text-muted-foreground">
                                They will see your filters, not your records — each person still
                                sees only what they may.
                            </span>
                        </span>
                    </label>
                @endif

                <div class="mt-5 flex items-center justify-end gap-2 border-t border-border pt-4">
                    <button
                        type="button"
                        wire:click="cancelSavingView"
                        class="rounded-lg px-4 py-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                    >Cancel</button>

                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="saveView"
                        class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90 disabled:opacity-60"
                    >Save view</button>
                </div>
            </form>
        </div>
    @endif
</div>
