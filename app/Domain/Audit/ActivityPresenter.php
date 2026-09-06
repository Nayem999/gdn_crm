<?php

namespace App\Domain\Audit;

use Spatie\Activitylog\Models\Activity;

/**
 * Turns a logged entry into something a person can read.
 *
 * The audit viewer and a record's timeline show the same entries in different
 * shapes, and both need the same three answers: what changed, how a logged
 * value reads, and what colour the event is. Kept in one place so the two
 * screens cannot drift into disagreeing about the same row.
 */
final class ActivityPresenter
{
    /**
     * The before/after pairs for one entry, keyed by attribute.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function changes(Activity $activity): array
    {
        /** @var array<string, mixed> $properties */
        $properties = $activity->properties->toArray();

        $new = is_array($properties['attributes'] ?? null) ? $properties['attributes'] : [];
        $old = is_array($properties['old'] ?? null) ? $properties['old'] : [];

        $changes = [];

        foreach (array_keys($new + $old) as $attribute) {
            $changes[$attribute] = [
                'old' => $old[$attribute] ?? null,
                'new' => $new[$attribute] ?? null,
            ];
        }

        return $changes;
    }

    /**
     * Render a logged value for display, including arrays such as a role's
     * permission list.
     */
    public static function value(mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn (mixed $item) => (string) $item, $value));
        }

        return (string) $value;
    }

    public static function color(?string $event): string
    {
        return match ($event) {
            'created' => 'emerald',
            'updated' => 'blue',
            'deleted' => 'rose',
            default => 'slate',
        };
    }

    /**
     * The attribute names that moved, as a person would name them.
     *
     * @return array<int, string>
     */
    public static function changedLabels(Activity $activity): array
    {
        return array_map(
            fn (string $attribute) => str($attribute)->headline()->toString(),
            array_keys(self::changes($activity))
        );
    }
}
