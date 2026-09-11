@php
    use App\Domain\Shared\UI\ChipPalette;
@endphp

<div>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ ChipPalette::classes($module->color) }}">
                <x-icon :name="'lucide-' . $module->icon" class="h-5 w-5" />
            </span>
            <div>
                <h1 class="text-2xl font-semibold text-foreground">{{ $module->plural_name }}</h1>
                @if ($module->description)
                    <p class="mt-1 text-sm text-muted-foreground">{{ $module->description }}</p>
                @endif
            </div>
        </div>

        @can('create', App\Domain\CustomModules\Models\CustomRecord::class)
            <a
                href="{{ route('custom-modules.create', $moduleKey) }}"
                wire:navigate
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90"
            >
                <x-icon name="lucide-plus" />
                Add {{ strtolower($module->name) }}
            </a>
        @endcan
    </div>

    <div
        x-data="{ message: '', tone: 'success' }"
        x-on:notify.window="tone = $event.detail.type === 'error' ? 'error' : 'success'; message = $event.detail.message; setTimeout(() => message = '', 4000)"
        x-show="message"
        x-cloak
        class="mb-4"
    >
        <template x-if="tone === 'error'"><x-alert variant="error"><span x-text="message"></span></x-alert></template>
        <template x-if="tone !== 'error'"><x-alert variant="success"><span x-text="message"></span></x-alert></template>
    </div>

    @if (session('status'))
        <x-alert variant="success" class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <x-data-view
        :view="$this"
        :records="$this->rows"
        search-placeholder="Search {{ strtolower($module->plural_name) }}…"
        :empty-icon="$module->icon"
        empty-heading="No {{ strtolower($module->plural_name) }} yet"
        empty-description="This module was added in Settings. Records you create appear here."
    >
        <x-slot:empty-actions>
            @can('create', App\Domain\CustomModules\Models\CustomRecord::class)
                <a href="{{ route('custom-modules.create', $moduleKey) }}" wire:navigate
                   class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90">
                    <x-icon name="lucide-plus" />
                    Add the first one
                </a>
            @endcan
        </x-slot:empty-actions>

    </x-data-view>
</div>
