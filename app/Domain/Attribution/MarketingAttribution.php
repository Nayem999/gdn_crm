<?php

namespace App\Domain\Attribution;

use App\Domain\Leads\Enums\LeadSource;
use Illuminate\Support\Carbon;

/**
 * Where a record came from, as a value.
 *
 * A value object rather than an array because attribution is copied more often
 * than it is created — a lead becomes an account, a contact and a deal, and
 * each has to carry the same answer — and an array copied four times is four
 * chances to drop a field.
 *
 * Everything is nullable. Attribution is what was known at the moment of
 * capture, and most of it is unknown for most records: a lead typed in by a
 * salesperson has a source and nothing else, and that is not a gap to fill in
 * with guesses.
 */
readonly class MarketingAttribution
{
    public function __construct(
        public ?string $source = null,
        public ?string $sourceDetail = null,
        public ?string $metaLeadId = null,
        public ?string $pageId = null,
        public ?string $formId = null,
        public ?string $formName = null,
        public ?string $metaCampaignId = null,
        public ?string $metaCampaignName = null,
        public ?string $metaAdSetId = null,
        public ?string $metaAdSetName = null,
        public ?string $metaAdId = null,
        public ?string $metaAdName = null,
        public ?string $clickId = null,
        public ?string $utmSource = null,
        public ?string $utmMedium = null,
        public ?string $utmCampaign = null,
        public ?string $utmContent = null,
        public ?string $utmTerm = null,
        public ?Carbon $capturedAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  Keyed by column name.
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            source: self::text($attributes, 'source'),
            sourceDetail: self::text($attributes, 'source_detail'),
            metaLeadId: self::text($attributes, 'meta_lead_id'),
            pageId: self::text($attributes, 'page_id'),
            formId: self::text($attributes, 'form_id'),
            formName: self::text($attributes, 'form_name'),
            metaCampaignId: self::text($attributes, 'meta_campaign_id'),
            metaCampaignName: self::text($attributes, 'meta_campaign_name'),
            metaAdSetId: self::text($attributes, 'meta_ad_set_id'),
            metaAdSetName: self::text($attributes, 'meta_ad_set_name'),
            metaAdId: self::text($attributes, 'meta_ad_id'),
            metaAdName: self::text($attributes, 'meta_ad_name'),
            clickId: self::text($attributes, 'click_id'),
            utmSource: self::text($attributes, 'utm_source'),
            utmMedium: self::text($attributes, 'utm_medium'),
            utmCampaign: self::text($attributes, 'utm_campaign'),
            utmContent: self::text($attributes, 'utm_content'),
            utmTerm: self::text($attributes, 'utm_term'),
            // A Carbon arrives from the model, a string from a payload, and
            // nothing at all from a hand-built value.
            capturedAt: match (true) {
                ($attributes['captured_at'] ?? null) instanceof Carbon => $attributes['captured_at'],
                is_string($attributes['captured_at'] ?? null) && $attributes['captured_at'] !== '' => Carbon::parse($attributes['captured_at']),
                default => null,
            },
        );
    }

    public static function forSource(LeadSource $source, ?string $detail = null): self
    {
        return new self(source: $source->value, sourceDetail: $detail);
    }

    /**
     * The columns, ready to write.
     *
     * `captured_at` defaults to now rather than being left null: the column is
     * not nullable, because an attribution with no moment attached cannot be
     * put on a timeline or counted in a period, which is most of what
     * attribution is for.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'source' => $this->source,
            'source_detail' => $this->sourceDetail,
            'meta_lead_id' => $this->metaLeadId,
            'page_id' => $this->pageId,
            'form_id' => $this->formId,
            'form_name' => $this->formName,
            'meta_campaign_id' => $this->metaCampaignId,
            'meta_campaign_name' => $this->metaCampaignName,
            'meta_ad_set_id' => $this->metaAdSetId,
            'meta_ad_set_name' => $this->metaAdSetName,
            'meta_ad_id' => $this->metaAdId,
            'meta_ad_name' => $this->metaAdName,
            'click_id' => $this->clickId,
            'utm_source' => $this->utmSource,
            'utm_medium' => $this->utmMedium,
            'utm_campaign' => $this->utmCampaign,
            'utm_content' => $this->utmContent,
            'utm_term' => $this->utmTerm,
            'captured_at' => $this->capturedAt ?? Carbon::now(),
        ];
    }

    /**
     * Whether there is anything here worth storing.
     *
     * A record with nothing but a `captured_at` is a row that says only "this
     * existed", which every record already says by existing.
     */
    public function isEmpty(): bool
    {
        foreach ($this->toAttributes() as $key => $value) {
            if ($key !== 'captured_at' && $value !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * This attribution, with anything missing taken from another.
     *
     * How a merge resolves two: the survivor's own answer wins field by field,
     * and the loser fills the gaps rather than overwriting. Neither record
     * knows more than it knows.
     */
    public function mergedWith(self $other): self
    {
        $mine = $this->toAttributes();
        $theirs = $other->toAttributes();

        foreach ($mine as $key => $value) {
            if ($value === null) {
                $mine[$key] = $theirs[$key] ?? null;
            }
        }

        // The earlier of the two moments: attribution is about first touch, and
        // the survivor being created later does not make the customer newer.
        $mine['captured_at'] = $this->capturedAt !== null && $other->capturedAt !== null
            ? ($this->capturedAt->lt($other->capturedAt) ? $this->capturedAt : $other->capturedAt)
            : ($this->capturedAt ?? $other->capturedAt);

        return self::fromArray($mine);
    }

    public function leadSource(): ?LeadSource
    {
        return $this->source === null ? null : LeadSource::tryFrom($this->source);
    }

    /**
     * Whether this came from Meta at all, which is what decides whether a
     * conversion event can be reported back.
     */
    public function isFromMeta(): bool
    {
        return $this->metaLeadId !== null
            || $this->metaCampaignId !== null
            || $this->metaAdId !== null
            || $this->clickId !== null;
    }

    /**
     * What the record's marketing attribution panel shows, in reading order.
     * Empty entries are left out rather than shown as dashes: a panel of eight
     * dashes tells nobody anything.
     *
     * @return array<string, string>
     */
    public function forDisplay(): array
    {
        $rows = [
            'Source' => $this->leadSource()?->label() ?? $this->source,
            'Detail' => $this->sourceDetail,
            'Campaign' => $this->metaCampaignName ?? $this->utmCampaign,
            'Ad set' => $this->metaAdSetName,
            'Ad' => $this->metaAdName,
            'Form' => $this->formName,
            'UTM source' => $this->utmSource,
            'UTM medium' => $this->utmMedium,
            'UTM content' => $this->utmContent,
            'UTM term' => $this->utmTerm,
            'Meta lead ID' => $this->metaLeadId,
        ];

        return array_filter($rows, fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function text(array $attributes, string $key): ?string
    {
        $value = $attributes[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
