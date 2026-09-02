@props(['for'])

@error($for)
    <p {{ $attributes->merge(['class' => 'mt-1 text-sm text-destructive']) }}>{{ $message }}</p>
@enderror
