<?php

namespace App\Domain\Social\Referrals;

/**
 * The advertisement a conversation started from, read out of Meta's payload.
 *
 * A customer who taps "Send message" on a Facebook or Instagram advertisement
 * arrives in the inbox as an ordinary message with one extra object attached,
 * and that object is the only moment the advertisement is ever named. It is not
 * repeated on their second message, it is not on the conversation, and it cannot
 * be asked for afterwards — so a referral not read here is a lead that came from
 * nowhere, and a campaign that will never be credited with the business it won.
 *
 * **Meta names the same thing differently on each channel**, which is the whole
 * reason this class exists rather than an array read at two call sites:
 *
 * | | WhatsApp | Messenger |
 * | --- | --- | --- |
 * | The ad | `source_id` | `ad_id` |
 * | Where from | `source_type` (`ad`, `post`) | `source` (`ADS`, `SHORTLINK`) |
 * | The click | `ctwa_clid` | — |
 * | A link's own label | — | `ref` |
 *
 * The click id is the field that earns its keep later: it is what 12.12 sends
 * back with a won deal, and the only thing that lets Meta join the money to the
 * click that produced it.
 */
readonly class ClickToMessageReferral
{
    public function __construct(
        public ?string $adId = null,
        public ?string $clickId = null,
        public ?string $sourceType = null,
        public ?string $sourceUrl = null,
        public ?string $headline = null,
        public ?string $body = null,
        public ?string $ref = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public static function fromPayload(?array $payload): ?self
    {
        if ($payload === null || $payload === []) {
            return null;
        }

        $referral = new self(
            // Both spellings, because one handler's payload uses each and a
            // referral read as null is indistinguishable from an organic
            // message — which is the failure that silently loses the
            // attribution rather than announcing itself.
            adId: self::text($payload, 'source_id') ?? self::text($payload, 'ad_id'),
            clickId: self::text($payload, 'ctwa_clid'),
            sourceType: self::text($payload, 'source_type') ?? self::text($payload, 'source'),
            sourceUrl: self::text($payload, 'source_url'),
            headline: self::text($payload, 'headline'),
            body: self::text($payload, 'body'),
            ref: self::text($payload, 'ref'),
        );

        // An envelope with none of the fields we understand is not a referral.
        // Meta adds keys to these objects without notice, and storing one that
        // names no advertisement would put an empty "came from an ad" panel on
        // a lead that came from a search.
        return $referral->isEmpty() ? null : $referral;
    }

    public function isEmpty(): bool
    {
        return $this->adId === null
            && $this->clickId === null
            && $this->ref === null
            && $this->sourceUrl === null;
    }

    /**
     * Whether this names an advertisement we can look up.
     *
     * A shortlink referral carries a `ref` label and no ad id: it says the
     * customer came from a link somebody put somewhere, which is worth recording
     * and is not a campaign.
     */
    public function namesAnAd(): bool
    {
        return $this->adId !== null;
    }

    /**
     * What to show when the advertisement is not one this CRM has synced — the
     * headline the customer actually saw, which is more use to an agent than an
     * id.
     */
    public function label(): ?string
    {
        return $this->headline ?? $this->ref ?? ($this->adId === null ? null : 'Advertisement '.$this->adId);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'ad_id' => $this->adId,
            'ctwa_clid' => $this->clickId,
            'source_type' => $this->sourceType,
            'source_url' => $this->sourceUrl,
            'headline' => $this->headline,
            'body' => $this->body,
            'ref' => $this->ref,
        ], fn (?string $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function text(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if (is_int($value)) {
            // Meta sends ad ids as numbers about as often as strings, and an id
            // stored as one and compared as the other never matches.
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
