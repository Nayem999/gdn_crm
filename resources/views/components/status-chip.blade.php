@props(['color' => 'slate'])

{{-- Minimal for now; task 1.7 expands this into the shared UI kit version that
     reads its colour from an enum's color() method. Keep the `color` prop. --}}
@php
    $palette = [
        'amber' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
        'blue' => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
        'indigo' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300',
        'emerald' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
        'violet' => 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
        'orange' => 'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300',
        'cyan' => 'bg-cyan-100 text-cyan-700 dark:bg-cyan-500/15 dark:text-cyan-300',
        'rose' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
        'teal' => 'bg-teal-100 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300',
        'fuchsia' => 'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-500/15 dark:text-fuchsia-300',
        'slate' => 'bg-slate-100 text-slate-700 dark:bg-slate-500/15 dark:text-slate-300',
    ];
@endphp

<span {{ $attributes->class([
    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
    $palette[$color] ?? $palette['slate'],
]) }}>
    {{ $slot }}
</span>
