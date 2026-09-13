<?php

namespace App\Domain\Ingestion;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Turns a value read from a payload into the value a field wants.
 *
 * The gap between two systems is rarely structural — the other side has the
 * name, the date and the status, it just writes them differently. These are the
 * differences that come up every time: one name where we keep two, a date in
 * the format that country writes dates in, and a status vocabulary that is
 * theirs rather than ours.
 *
 * **An unknown transform passes the value through unchanged rather than
 * failing.** A mapping configured by an older version of the application, or by
 * a rule somebody removed, should not stop a delivery that is otherwise fine —
 * the alternative turns a configuration mistake into an outage for every event
 * that source sends.
 *
 * **A transform that cannot do its job returns null, never a guess.** A date it
 * cannot parse is not a date; writing today's date instead would put a
 * confident wrong answer in a column, which is worse than an empty one and far
 * harder to notice.
 */
class ValueTransformer
{
    /**
     * @param  array<string, mixed>|null  $options
     */
    public function apply(?string $transform, string|int|float|bool|null $value, ?array $options = null): string|int|float|bool|null
    {
        if ($transform === null || $transform === '' || $value === null) {
            return $value;
        }

        return match ($transform) {
            'trim' => is_string($value) ? trim($value) : $value,
            'upper' => is_string($value) ? mb_strtoupper($value) : $value,
            'lower' => is_string($value) ? mb_strtolower($value) : $value,
            'value_map' => $this->valueMap($value, $options),
            'date' => $this->date($value, $options),
            'name_first' => $this->namePart($value, 'first'),
            'name_last' => $this->namePart($value, 'last'),
            'digits' => $this->digits($value),
            default => $value,
        };
    }

    /**
     * Their vocabulary into ours.
     *
     * Matched case-insensitively, because a system that sends "Open" today will
     * send "OPEN" the day somebody refactors it. An unmapped value falls
     * through to the configured fallback, and with no fallback it is **left
     * alone** rather than blanked: a status we have not seen before is
     * information, and the validation rules are where it gets refused if it is
     * not allowed.
     *
     * @param  array<string, mixed>|null  $options
     */
    private function valueMap(string|int|float|bool $value, ?array $options): string|int|float|bool|null
    {
        $map = $options['map'] ?? null;

        if (! is_array($map)) {
            return $value;
        }

        $needle = mb_strtolower((string) $value);

        foreach ($map as $from => $to) {
            if (mb_strtolower((string) $from) === $needle) {
                return is_scalar($to) ? $to : null;
            }
        }

        $fallback = $options['fallback'] ?? null;

        return is_scalar($fallback) && $fallback !== '' ? $fallback : $value;
    }

    /**
     * A date in their format, as a date in ours.
     *
     * The incoming format is **stated**, not guessed. `03/04/2026` is the third
     * of April in most of the world and the fourth of March in the United
     * States, and there is nothing in the string that says which — a parser
     * left to guess will be quietly wrong for eleven days of every month.
     *
     * With no format given it falls back to Carbon's own parsing, which handles
     * ISO-8601 and the timestamps most APIs actually send.
     *
     * @param  array<string, mixed>|null  $options
     */
    private function date(string|int|float|bool $value, ?array $options): ?string
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        $from = is_string($options['from'] ?? null) && $options['from'] !== '' ? $options['from'] : null;
        $to = is_string($options['to'] ?? null) && $options['to'] !== '' ? $options['to'] : 'Y-m-d';

        try {
            $date = $from === null
                ? Carbon::parse($raw)
                : Carbon::createFromFormat($from, $raw);
        } catch (Throwable) {
            // Not a date we can read. Null, never today: a confident wrong
            // answer in a date column is worse than an empty one.
            return null;
        }

        // Null rather than false: this Carbon returns null for a format it
        // cannot satisfy, and it does not always throw to say so.
        return $date?->format($to);
    }

    /**
     * One name where we keep two.
     *
     * The **last** whitespace-separated part is the surname and everything
     * before it is the rest, because that is right for "Dara Okafor" and for
     * "Maria del Carmen Okafor", where taking the second word would be wrong.
     * A single word is a first name with no surname, not a surname with no
     * first name: somebody called "Cher" is called Cher.
     */
    private function namePart(string|int|float|bool $value, string $part): ?string
    {
        $words = preg_split('/\s+/', trim((string) $value)) ?: [];
        $words = array_values(array_filter($words, fn (string $word) => $word !== ''));

        if ($words === []) {
            return null;
        }

        if (count($words) === 1) {
            return $part === 'first' ? $words[0] : null;
        }

        return $part === 'first'
            ? implode(' ', array_slice($words, 0, -1))
            : $words[count($words) - 1];
    }

    /**
     * Everything that is not a digit removed, for the phone numbers that arrive
     * as "+44 (0) 1392 555 010".
     */
    private function digits(string|int|float|bool $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits === null || $digits === '' ? null : $digits;
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
            'value_map' => 'Translate their values into ours',
            'date' => 'Read as a date',
            'name_first' => 'Take the first name out of a full name',
            'name_last' => 'Take the surname out of a full name',
            'digits' => 'Keep only the digits',
        ];
    }

    /**
     * Which options a transform needs configuring with, so the screen can show
     * the right controls and nothing else.
     *
     * @return array<int, string>
     */
    public function configurableOptions(string $transform): array
    {
        return match ($transform) {
            'value_map' => ['map', 'fallback'],
            'date' => ['from'],
            default => [],
        };
    }

    public function needsConfiguring(string $transform): bool
    {
        return $this->configurableOptions($transform) !== [];
    }
}
