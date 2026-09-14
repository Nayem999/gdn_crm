<?php

namespace App\Domain\Campaigns\Enums;

use App\Domain\Leads\Enums\LeadSource;

/**
 * What kind of marketing a campaign is.
 *
 * Paid social is one case rather than one per network, because the network is
 * already recorded on the lead's source and on the Meta campaign a CRM campaign
 * is linked to — a `facebook` type here would be a second answer to the same
 * question, and the two would disagree the first time somebody ran the same
 * offer on two networks.
 */
enum CampaignType: string
{
    case PaidSocial = 'paid_social';
    case PaidSearch = 'paid_search';
    case Email = 'email';
    case Event = 'event';
    case Webinar = 'webinar';
    case Telemarketing = 'telemarketing';
    case Referral = 'referral';
    case Content = 'content';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PaidSocial => 'Paid social',
            self::PaidSearch => 'Paid search',
            self::Email => 'Email',
            self::Event => 'Event',
            self::Webinar => 'Webinar',
            self::Telemarketing => 'Telemarketing',
            self::Referral => 'Referral',
            self::Content => 'Content',
            self::Other => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PaidSocial => 'violet',
            self::PaidSearch => 'blue',
            self::Email => 'cyan',
            self::Event => 'orange',
            self::Webinar => 'amber',
            self::Telemarketing => 'rose',
            self::Referral => 'emerald',
            self::Content => 'teal',
            self::Other => 'slate',
        };
    }

    /**
     * Whether a Meta campaign can be linked to one of this type.
     *
     * Only paid social and paid search, because linking an email campaign to a
     * Facebook ad set would attribute one channel's leads to another's spend —
     * and the point of the whole exercise is a cost per lead somebody can act
     * on.
     */
    public function acceptsMetaLink(): bool
    {
        return $this === self::PaidSocial || $this === self::PaidSearch;
    }

    /**
     * The lead source a campaign of this type usually produces, used to
     * pre-select the source when a lead is created from it. A suggestion, never
     * an override: a referral from an event is both, and the person entering it
     * knows which mattered.
     */
    public function suggestedLeadSource(): LeadSource
    {
        return match ($this) {
            self::PaidSocial => LeadSource::SocialMedia,
            self::PaidSearch, self::Content => LeadSource::Advertising,
            self::Email => LeadSource::Email,
            self::Event, self::Webinar => LeadSource::Event,
            self::Telemarketing => LeadSource::ColdCall,
            self::Referral => LeadSource::Referral,
            self::Other => LeadSource::Other,
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
