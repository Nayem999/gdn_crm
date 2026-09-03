@props(['title', 'subtitle' => null, 'meta' => []])

{{-- Visible on screen as a plain sheet, and the only thing that survives
     printing — the app chrome is hidden by the @media print rules in app.css. --}}
<div {{ $attributes->merge(['class' => 'print-sheet rounded-xl border border-border bg-card p-6']) }}>
    <header class="flex items-start justify-between gap-4 border-b border-border pb-4">
        <div>
            <h1 class="text-lg font-semibold text-foreground">{{ $title }}</h1>

            @if ($subtitle)
                <p class="mt-0.5 text-sm text-muted-foreground">{{ $subtitle }}</p>
            @endif
        </div>

        <div class="text-right text-xs text-muted-foreground">
            <p>{{ config('app.name') }}</p>
            <p>{{ now()->format('j M Y, H:i') }}</p>
        </div>
    </header>

    @if ($meta !== [])
        <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
            @foreach ($meta as $label => $value)
                <div>
                    <dt class="text-xs uppercase tracking-wide text-muted-foreground">{{ $label }}</dt>
                    <dd class="text-foreground">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    @endif

    <div class="mt-5">
        {{ $slot }}
    </div>

    @if (isset($footer))
        <footer class="mt-6 border-t border-border pt-3 text-xs text-muted-foreground">
            {{ $footer }}
        </footer>
    @endif
</div>
