@props(['for' => null, 'required' => false])

<label {{ $attributes->merge(['for' => $for, 'class' => 'mb-1.5 block text-sm font-medium text-foreground']) }}>
    {{ $slot }}
    @if ($required)
        <span class="text-destructive" aria-hidden="true">*</span>
    @endif
</label>
