<?php

namespace App\Domain\Shared\UI;

/**
 * The colour each navigation icon wears.
 *
 * Keyed on the **icon name** rather than the menu label, so the same icon is
 * the same colour wherever it appears: `building-2` is indigo both as Accounts
 * in the main sidebar and as Company under Settings, and `trending-up` is lime
 * in both places it is listed. Keying on the label would have let those drift.
 *
 * Two surfaces, two answers. The main sidebar is dark in both themes, so the
 * 400 shade is right there and a 600 would disappear. The Settings navigation
 * sits on the page background, which follows the theme, so it needs the pair.
 *
 * Tailwind cannot see a class name that was built at runtime, so every class is
 * written out in full here — the same reason ChipPalette does.
 */
final class NavIconPalette
{
    /**
     * @var array<string, array{dark: string, page: string}>
     */
    private const TONES = [
        'sky' => ['dark' => 'text-sky-400', 'page' => 'text-sky-600 dark:text-sky-400'],
        'blue' => ['dark' => 'text-blue-400', 'page' => 'text-blue-600 dark:text-blue-400'],
        'indigo' => ['dark' => 'text-indigo-400', 'page' => 'text-indigo-600 dark:text-indigo-400'],
        'violet' => ['dark' => 'text-violet-400', 'page' => 'text-violet-600 dark:text-violet-400'],
        'purple' => ['dark' => 'text-purple-400', 'page' => 'text-purple-600 dark:text-purple-400'],
        'fuchsia' => ['dark' => 'text-fuchsia-400', 'page' => 'text-fuchsia-600 dark:text-fuchsia-400'],
        'pink' => ['dark' => 'text-pink-400', 'page' => 'text-pink-600 dark:text-pink-400'],
        'rose' => ['dark' => 'text-rose-400', 'page' => 'text-rose-600 dark:text-rose-400'],
        'red' => ['dark' => 'text-red-400', 'page' => 'text-red-600 dark:text-red-400'],
        'orange' => ['dark' => 'text-orange-400', 'page' => 'text-orange-600 dark:text-orange-400'],
        'amber' => ['dark' => 'text-amber-400', 'page' => 'text-amber-600 dark:text-amber-400'],
        'yellow' => ['dark' => 'text-yellow-400', 'page' => 'text-yellow-600 dark:text-yellow-400'],
        'lime' => ['dark' => 'text-lime-400', 'page' => 'text-lime-600 dark:text-lime-400'],
        'emerald' => ['dark' => 'text-emerald-400', 'page' => 'text-emerald-600 dark:text-emerald-400'],
        'teal' => ['dark' => 'text-teal-400', 'page' => 'text-teal-600 dark:text-teal-400'],
        'cyan' => ['dark' => 'text-cyan-400', 'page' => 'text-cyan-600 dark:text-cyan-400'],
    ];

    /**
     * Icon name to tone.
     *
     * Chosen so that neighbours in a list differ and so that a few read the way
     * people already expect them to — WhatsApp green, Messenger blue, money
     * amber, an SLA timer red.
     *
     * @var array<string, string>
     */
    private const ICONS = [
        // The main sidebar.
        'layout-dashboard' => 'sky',
        'target' => 'rose',
        'contact' => 'cyan',
        'building-2' => 'indigo',
        'handshake' => 'emerald',
        'calendar-clock' => 'amber',
        'calendar-days' => 'orange',
        'package' => 'violet',
        'file-text' => 'blue',
        'life-buoy' => 'teal',
        'book-open' => 'fuchsia',
        'megaphone' => 'orange',
        'message-circle' => 'emerald',
        'messages-square' => 'blue',
        'trending-up' => 'lime',
        'badge-dollar-sign' => 'amber',
        'bar-chart-3' => 'violet',
        'zap' => 'yellow',
        'settings' => 'purple',
        'circle-help' => 'pink',

        // Under Settings.
        'users' => 'sky',
        'network' => 'cyan',
        'shield-check' => 'emerald',
        'gauge' => 'amber',
        'clipboard-list' => 'orange',
        'git-branch' => 'violet',
        'tags' => 'pink',
        'sliders-horizontal' => 'teal',
        'box' => 'blue',
        'mail-plus' => 'rose',
        'timer' => 'red',
        'bell-ring' => 'amber',
        'mail-check' => 'emerald',
        // Pink, which is what is left once both of its neighbours are
        // accounted for: cyan and violet surround it under General, emerald
        // and orange under System.
        'share-2' => 'pink',
        'antenna' => 'cyan',
        'scroll-text' => 'violet',
        'key-round' => 'yellow',
        'webhook' => 'purple',

        // The settings registry's own groups.
        'globe' => 'sky',
        // Purple: Notification limits is listed between Scheduling (amber)
        // and Email providers (rose), and adjacency is the one thing this map
        // has to be careful about — amber would have matched the row above and
        // pink was too near the row below.
        'bell' => 'purple',
        'mail' => 'rose',
        'plug' => 'lime',
        'message-square' => 'violet',
        'inbox' => 'indigo',
        'hard-drive' => 'cyan',
    ];

    /**
     * For the main sidebar, which is dark in both themes.
     */
    public static function onDark(string $icon): string
    {
        return self::TONES[self::toneFor($icon)]['dark'];
    }

    /**
     * For navigation on the page background, which follows the theme.
     */
    public static function onPage(string $icon): string
    {
        return self::TONES[self::toneFor($icon)]['page'];
    }

    /**
     * An icon nobody listed still gets a colour rather than falling back to
     * grey — a module an administrator created at runtime picks its own icon,
     * and so will the next settings group somebody adds.
     *
     * Derived from the name, so it is stable: the same module is the same
     * colour on every request and for every user, which is what makes the
     * colour worth anything as a landmark.
     */
    private static function toneFor(string $icon): string
    {
        if (isset(self::ICONS[$icon])) {
            return self::ICONS[$icon];
        }

        $tones = array_keys(self::TONES);

        return $tones[crc32($icon) % count($tones)];
    }
}
