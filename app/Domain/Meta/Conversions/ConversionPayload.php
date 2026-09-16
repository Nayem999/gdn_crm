<?php

namespace App\Domain\Meta\Conversions;

use App\Domain\Attribution\MarketingAttribution;
use App\Domain\Meta\Conversions\Enums\ConversionOutcome;
use Illuminate\Support\Carbon;

/**
 * One event, in the shape Meta's Conversions API accepts.
 *
 * **Every identifier is hashed before it leaves.** Meta requires SHA-256 of the
 * normalised value for an email address or a telephone number, and the
 * normalisation is not optional decoration: "  Dara@Example.COM " and
 * "dara@example.com" hash differently, so a mis-normalised identifier is not a
 * privacy failure but a silent matching failure — the event arrives, Meta finds
 * nobody, and the campaign shows no conversions while the CRM's log says every
 * one was sent.
 *
 * The click id is sent **unhashed**, because it is not a person: it is Meta's
 * own token for one tap on one advertisement, and hashing it would make it
 * unmatchable.
 *
 * `action_source` tells Meta where the conversion happened. For a customer who
 * came through a click-to-message advertisement that is `business_messaging`
 * with the channel named; for a lead form or anything else it is
 * `system_generated`, which is what Meta expects from a CRM reporting an
 * outcome that happened off-platform.
 */
class ConversionPayload
{
    /**
     * @param  array{email?: string|null, phone?: string|null}  $identifiers
     * @return array<string, mixed>
     */
    public static function build(
        ConversionOutcome $outcome,
        string $eventId,
        MarketingAttribution $attribution,
        array $identifiers,
        Carbon $occurredAt,
        ?float $value = null,
        ?string $currency = null,
    ): array {
        $event = [
            'event_name' => $outcome->eventName(),
            'event_time' => $occurredAt->getTimestamp(),
            'event_id' => $eventId,
            'action_source' => self::actionSource($attribution),
            'user_data' => self::userData($attribution, $identifiers),
        ];

        if ($attribution->clickId !== null) {
            // Only meaningful alongside business_messaging, and Meta ignores it
            // elsewhere rather than refusing — but sending it only where it
            // belongs keeps the stored payload honest about what was claimed.
            $event['messaging_channel'] = 'whatsapp';
        }

        if ($outcome->carriesValue() && $value !== null) {
            $event['custom_data'] = array_filter([
                'value' => round($value, 2),
                'currency' => $currency,
            ], fn (mixed $entry): bool => $entry !== null);
        }

        return $event;
    }

    /**
     * Whether there is anything here Meta could match a person by.
     *
     * An event with no identifier is accepted and matched to nobody, so it
     * inflates the CRM's own log while changing nothing at Meta. Refusing to
     * send it is the honest outcome.
     *
     * @param  array{email?: string|null, phone?: string|null}  $identifiers
     */
    public static function isMatchable(MarketingAttribution $attribution, array $identifiers): bool
    {
        return $attribution->clickId !== null
            || $attribution->metaLeadId !== null
            || self::text($identifiers, 'email') !== null
            || self::text($identifiers, 'phone') !== null;
    }

    private static function actionSource(MarketingAttribution $attribution): string
    {
        return $attribution->clickId !== null ? 'business_messaging' : 'system_generated';
    }

    /**
     * @param  array{email?: string|null, phone?: string|null}  $identifiers
     * @return array<string, mixed>
     */
    private static function userData(MarketingAttribution $attribution, array $identifiers): array
    {
        $email = self::text($identifiers, 'email');
        $phone = self::text($identifiers, 'phone');

        return array_filter([
            // Meta wants these as arrays: one person can have several.
            'em' => $email === null ? null : [hash('sha256', mb_strtolower(trim($email)))],
            // Digits only, country code included, before hashing. A number
            // stored as "+44 7700 900123" and one stored as "07700900123" are
            // the same customer and must hash the same.
            'ph' => $phone === null ? null : [hash('sha256', self::digits($phone))],
            // Not hashed: Meta's own token for one click, useless to anybody
            // else and unmatchable if obscured.
            'ctwa_clid' => $attribution->clickId,
            // The lead ads identifier, which Meta matches directly against the
            // submission it already has.
            'lead_id' => $attribution->metaLeadId === null ? null : (int) $attribution->metaLeadId,
        ], fn (mixed $entry): bool => $entry !== null);
    }

    private static function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * @param  array{email?: string|null, phone?: string|null}  $identifiers
     */
    private static function text(array $identifiers, string $key): ?string
    {
        $value = $identifiers[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
