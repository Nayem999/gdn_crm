@props([
    'value',
    'label' => null,
    'hint' => null,
    // Monospace by default: everything copied out of this application is an
    // address, a token or a snippet, and a proportional font makes an l and a 1
    // the same character at exactly the moment that matters.
    'mono' => true,
])

@php($field = 'copy-'.Str::random(8))

<div x-data="{ copied: false }">
    @if ($label)
        <label for="{{ $field }}" class="text-xs font-medium text-muted-foreground">{{ $label }}</label>
    @endif

    <div @class(['flex gap-2', 'mt-1' => $label])>
        {{-- Readonly rather than disabled: a disabled input cannot be selected,
             and selecting the text by hand is the fallback for every browser
             that refuses the clipboard API. --}}
        <input
            id="{{ $field }}"
            type="text"
            readonly
            value="{{ $value }}"
            x-ref="value"
            @class([
                'w-full rounded-lg border border-border bg-muted/40 px-3 py-2 text-xs text-muted-foreground',
                'font-mono' => $mono,
            ])
        >

        <button
            type="button"
            x-on:click="
                $refs.value.select();
                // The clipboard API is refused outside a secure context, so the
                // older command stands behind it — otherwise the button does
                // nothing at all on an installation served over http, with no
                // sign that it failed.
                (navigator.clipboard?.writeText($refs.value.value) ?? Promise.reject())
                    .catch(() => document.execCommand('copy'))
                    .finally(() => { copied = true; setTimeout(() => copied = false, 2000); });
            "
            class="shrink-0 rounded-lg border border-border px-3 text-xs font-medium text-foreground hover:bg-muted"
        >
            <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
        </button>
    </div>

    @if ($hint)
        <p class="mt-1.5 text-xs text-muted-foreground">{{ $hint }}</p>
    @endif
</div>
