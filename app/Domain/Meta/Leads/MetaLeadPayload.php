<?php

namespace App\Domain\Meta\Leads;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * A retrieved lead, in a shape a mapping can be written against.
 *
 * Graph answers with the answers as a **list**:
 *
 *     "field_data": [{"name": "email", "values": ["dara@example.com"]}, …]
 *
 * which is unmappable. `field_data.0.values.0` is the email today and the phone
 * number the morning somebody adds a question to the form, so a mapping written
 * against a position is a mapping that silently starts writing the wrong column.
 * Keyed by the question's own name instead — `field_data.email` — which is what
 * Meta guarantees is stable, and what the form's own definition lists.
 *
 * Everything here is **data**. A question name becomes a key in this array and
 * never anything else: no column, no class, no path outside it. The mapping
 * that reads it is written by an administrator against the module's declared
 * fields, and that is the only thing that decides where a value lands.
 */
final class MetaLeadPayload
{
    /**
     * @param  array<string, mixed>  $lead  As Graph returned it.
     * @return array<string, mixed>
     */
    public static function normalise(array $lead, ?string $formName = null): array
    {
        return [
            'id' => self::text($lead, 'id'),
            'created_time' => self::text($lead, 'created_time'),
            'form_id' => self::text($lead, 'form_id'),
            'form_name' => $formName,
            'campaign_id' => self::text($lead, 'campaign_id'),
            'campaign_name' => self::text($lead, 'campaign_name'),
            // Meta writes it without the underscore, and this keeps their name:
            // an administrator reading Meta's documentation beside the mapping
            // screen should see the same word in both.
            'adset_id' => self::text($lead, 'adset_id'),
            'adset_name' => self::text($lead, 'adset_name'),
            'ad_id' => self::text($lead, 'ad_id'),
            'ad_name' => self::text($lead, 'ad_name'),
            'platform' => self::text($lead, 'platform'),
            'is_organic' => $lead['is_organic'] ?? null,
            'field_data' => self::answers($lead),
        ];
    }

    /**
     * The answers, one value per question.
     *
     * Meta sends `values` as an array because a checkbox question can have
     * several. The first is taken for a field of ours, which holds one — and
     * the rest are deliberately dropped rather than joined into a string that
     * would land in a column nobody expected a list in.
     *
     * @param  array<string, mixed>  $lead
     * @return array<string, string>
     */
    public static function answers(array $lead): array
    {
        $answers = [];

        foreach (is_array($lead['field_data'] ?? null) ? $lead['field_data'] : [] as $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = $field['name'] ?? null;

            // A name with a dot in it is unreachable by a dotted mapping path.
            // Left as it is rather than rewritten: a mapping has to name what
            // Meta actually sends, and a renamed key would be a path that
            // matches nothing the day the rewriting rule changes.
            if (! is_string($name) || $name === '' || array_key_exists($name, $answers)) {
                continue;
            }

            foreach (is_array($field['values'] ?? null) ? $field['values'] : [] as $value) {
                if (is_scalar($value) && (string) $value !== '') {
                    $answers[$name] = (string) $value;

                    break;
                }
            }
        }

        return $answers;
    }

    /**
     * When the customer submitted it, according to Meta.
     *
     * Their moment, not ours: a lead retrieved by a backfill three days late is
     * still a lead from Tuesday, and attributing it to the moment we caught up
     * would put it in the wrong week of every report.
     *
     * @param  array<string, mixed>  $lead
     */
    public static function submittedAt(array $lead): ?Carbon
    {
        $value = $lead['created_time'] ?? null;

        if (is_int($value)) {
            return Carbon::createFromTimestamp($value);
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            // Their format, not ours. A moment we cannot read is no moment at
            // all — the caller falls back to now rather than to a guess.
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $lead
     */
    private static function text(array $lead, string $key): ?string
    {
        $value = $lead[$key] ?? null;

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
