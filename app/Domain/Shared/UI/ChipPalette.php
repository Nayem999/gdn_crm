<?php

namespace App\Domain\Shared\UI;

/**
 * The one place chip colours are defined.
 *
 * Blade components and server-side cell rendering both read it, so a status
 * chip looks the same whether it was written in a template or returned from a
 * data view's cellFor(). Tailwind cannot see class names built at runtime, so
 * every class is written out here in full rather than interpolated.
 */
final class ChipPalette
{
    private const TONES = [
        'amber' => ['chip' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300', 'dot' => 'bg-amber-500'],
        'blue' => ['chip' => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300', 'dot' => 'bg-blue-500'],
        'indigo' => ['chip' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300', 'dot' => 'bg-indigo-500'],
        'emerald' => ['chip' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300', 'dot' => 'bg-emerald-500'],
        'violet' => ['chip' => 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300', 'dot' => 'bg-violet-500'],
        'orange' => ['chip' => 'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300', 'dot' => 'bg-orange-500'],
        'cyan' => ['chip' => 'bg-cyan-100 text-cyan-700 dark:bg-cyan-500/15 dark:text-cyan-300', 'dot' => 'bg-cyan-500'],
        'rose' => ['chip' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300', 'dot' => 'bg-rose-500'],
        'teal' => ['chip' => 'bg-teal-100 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300', 'dot' => 'bg-teal-500'],
        'fuchsia' => ['chip' => 'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-500/15 dark:text-fuchsia-300', 'dot' => 'bg-fuchsia-500'],
        'slate' => ['chip' => 'bg-slate-100 text-slate-700 dark:bg-slate-500/15 dark:text-slate-300', 'dot' => 'bg-slate-500'],
    ];

    public const BASE = 'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium';

    public static function classes(?string $color): string
    {
        return (self::TONES[$color] ?? self::TONES['slate'])['chip'];
    }

    public static function dotClasses(?string $color): string
    {
        return (self::TONES[$color] ?? self::TONES['slate'])['dot'];
    }

    public static function has(string $color): bool
    {
        return array_key_exists($color, self::TONES);
    }

    /**
     * @return array<int, string>
     */
    public static function colors(): array
    {
        return array_keys(self::TONES);
    }

    /**
     * A ready-made chip, for code that renders a cell rather than a template.
     */
    public static function chip(string $label, ?string $color): string
    {
        return '<span class="'.self::BASE.' '.self::classes($color).'">'.e($label).'</span>';
    }
}
