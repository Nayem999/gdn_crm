<?php

namespace App\Domain\Meta\Enums;

/**
 * The three altitudes Meta reports figures at.
 *
 * The values are **Meta's own** — `adset`, not `ad_set` — because they are sent
 * as the `level` parameter on every insights call and stored in the column the
 * same call fills. One spelling for both ends of the round trip: a translation
 * layer here would be a place for a typo to live where nothing could catch it.
 */
enum MetaAdLevel: string
{
    case Campaign = 'campaign';
    case AdSet = 'adset';
    case Ad = 'ad';

    public function label(): string
    {
        return match ($this) {
            self::Campaign => 'Campaign',
            self::AdSet => 'Ad set',
            self::Ad => 'Ad',
        };
    }

    /**
     * The edge on an ad account that lists objects at this level.
     */
    public function edge(): string
    {
        return match ($this) {
            self::Campaign => 'campaigns',
            self::AdSet => 'adsets',
            self::Ad => 'ads',
        };
    }

    /**
     * The key Meta's insights rows carry this level's id under.
     *
     * Insights are asked for at a level and come back naming the object they
     * belong to, so this is how a row is matched to the thing it describes.
     */
    public function idField(): string
    {
        return match ($this) {
            self::Campaign => 'campaign_id',
            self::AdSet => 'adset_id',
            self::Ad => 'ad_id',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
