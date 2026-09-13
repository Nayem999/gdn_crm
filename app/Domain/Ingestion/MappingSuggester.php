<?php

namespace App\Domain\Ingestion;

use App\Domain\Shared\Imports\ImportField;

/**
 * Guesses which payload path belongs to which field.
 *
 * A suggestion, never a decision: it fills the screen in so somebody can
 * correct three rows instead of typing twenty, and everything it proposes is
 * editable before it is saved. Nothing here writes anything.
 *
 * It matches on the **last segment** of a path, because that is where the name
 * is — `contact.email`, `data.attributes.email` and `email` are all the email,
 * and the prefix is somebody else's envelope. A whole-path match would find
 * almost nothing.
 */
final class MappingSuggester
{
    /**
     * How close two names have to be before it is worth proposing. Below this
     * the suggestions are noise, and noise in a screen like this is worse than
     * a blank row: a wrong mapping that looks deliberate gets saved.
     */
    private const THRESHOLD = 72;

    /**
     * @param  array<int, string>  $paths  every leaf path in the sample
     * @param  array<string, ImportField>  $fields  the target module's fields
     * @return array<string, string> field key => source path
     */
    public static function suggest(array $paths, array $fields): array
    {
        $suggestions = [];
        $taken = [];

        foreach ($fields as $key => $field) {
            $best = null;
            $bestScore = 0;

            foreach ($paths as $path) {
                // One path per field: a sample with both `email` and
                // `contact.email` should not map the same value twice.
                if (in_array($path, $taken, true)) {
                    continue;
                }

                $score = self::score($path, $key, $field->label);

                if ($score > $bestScore) {
                    $best = $path;
                    $bestScore = $score;
                }
            }

            if ($best !== null && $bestScore >= self::THRESHOLD) {
                $suggestions[$key] = $best;
                $taken[] = $best;
            }
        }

        return $suggestions;
    }

    /**
     * How alike a path and a field are, out of 100.
     */
    private static function score(string $path, string $fieldKey, string $label): int
    {
        $segment = self::normalise((string) (explode('.', $path)[count(explode('.', $path)) - 1] ?? ''));
        $key = self::normalise($fieldKey);
        $labelKey = self::normalise($label);

        if ($segment === '') {
            return 0;
        }

        // An exact match on either the key or the label is as good as it gets,
        // and the label matters because a payload says "surname" where the
        // column says "last_name".
        if ($segment === $key || $segment === $labelKey) {
            return 100;
        }

        $best = 0;

        foreach ([$key, $labelKey] as $candidate) {
            if ($candidate === '') {
                continue;
            }

            similar_text($segment, $candidate, $percent);
            $best = max($best, (int) round($percent));
        }

        return $best;
    }

    /**
     * Case, spaces, underscores and hyphens all removed, so `last_name`,
     * `lastName`, `Last Name` and `last-name` are one thing.
     */
    private static function normalise(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));
    }
}
