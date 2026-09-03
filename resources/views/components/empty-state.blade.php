@props([
    'icon' => 'inbox',
    'heading' => 'Nothing here yet',
    'description' => null,
    'filtered' => false,
])

{{-- Two distinct voices: an empty module invites the first record, while an
     empty *filtered* result offers a way back. --}}
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center rounded-xl border border-dashed border-border px-6 py-14 text-center']) }}>
    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
        <x-icon :name="'lucide-' . ($filtered ? 'search-x' : $icon)" class="h-6 w-6" />
    </div>

    <h3 class="mt-4 text-base font-semibold text-foreground">
        {{ $filtered ? 'No matching records' : $heading }}
    </h3>

    <p class="mt-1 max-w-sm text-sm text-muted-foreground">
        {{ $description ?? ($filtered
            ? 'Try widening or clearing your filters to see more.'
            : 'Records you create will appear here.') }}
    </p>

    @if (isset($actions))
        <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
            {{ $actions }}
        </div>
    @endif
</div>
