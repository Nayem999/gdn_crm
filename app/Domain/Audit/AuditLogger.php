<?php

namespace App\Domain\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Writes audit entries for records the RecordsActivity trait cannot cover.
 *
 * Roles live in spatie/laravel-permission's own model, so the trait cannot be
 * attached without swapping the configured model class. These entries are shaped
 * exactly like the trait's so the viewer treats them identically.
 */
final class AuditLogger
{
    public const LOG_NAME = 'audit';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function created(Model $subject, string $label, array $attributes): void
    {
        self::write($subject, 'created', $label.' was created', ['attributes' => $attributes]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $old
     */
    public static function updated(Model $subject, string $label, array $attributes, array $old): void
    {
        // Only report what actually moved, matching logOnlyDirty() on the trait.
        $changed = array_keys(array_filter(
            $attributes,
            fn (mixed $value, string $key) => ($old[$key] ?? null) !== $value,
            ARRAY_FILTER_USE_BOTH
        ));

        if ($changed === []) {
            return;
        }

        self::write($subject, 'updated', $label.' was updated', [
            'attributes' => array_intersect_key($attributes, array_flip($changed)),
            'old' => array_intersect_key($old, array_flip($changed)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function deleted(Model $subject, string $label, array $attributes): void
    {
        self::write($subject, 'deleted', $label.' was deleted', ['old' => $attributes]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private static function write(Model $subject, string $event, string $description, array $properties): void
    {
        activity(self::LOG_NAME)
            ->performedOn($subject)
            ->event($event)
            ->withProperties($properties)
            ->log($description);
    }
}
