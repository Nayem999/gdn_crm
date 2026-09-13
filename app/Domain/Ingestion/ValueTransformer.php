<?php

namespace App\Domain\Ingestion;

/**
 * Turns a value read from a payload into the value a field wants.
 *
 * The seam task 8.6 fills in — value maps, date parsing, name splitting,
 * defaults. It exists now, with nothing in it, because the pipeline reads the
 * `transform` column and a column nothing reads is a column that quietly stops
 * being true.
 *
 * **An unknown transform passes the value through unchanged rather than
 * failing.** A mapping configured by an older version of the application, or by
 * a rule somebody removed, should not stop a delivery that is otherwise fine —
 * and the alternative, throwing, turns a configuration mistake into an outage
 * for every event that source sends.
 */
class ValueTransformer
{
    /**
     * @param  array<string, mixed>|null  $options
     */
    public function apply(?string $transform, string|int|float|bool|null $value, ?array $options = null): string|int|float|bool|null
    {
        if ($transform === null || $transform === '') {
            return $value;
        }

        return match ($transform) {
            // The handful that are obviously text tidying rather than a rule
            // anybody configures. 8.6 adds the rest.
            'trim' => is_string($value) ? trim($value) : $value,
            'upper' => is_string($value) ? mb_strtoupper($value) : $value,
            'lower' => is_string($value) ? mb_strtolower($value) : $value,
            default => $value,
        };
    }

    /**
     * The transforms that can be chosen, for a screen to offer.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return [
            'trim' => 'Trim surrounding spaces',
            'upper' => 'Upper case',
            'lower' => 'Lower case',
        ];
    }
}
