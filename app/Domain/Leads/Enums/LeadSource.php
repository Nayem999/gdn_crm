<?php

namespace App\Domain\Leads\Enums;

/**
 * Where a lead came from.
 *
 * Task 8.7 maps an external project system onto Other; the ingest gateway
 * stores its own source_id alongside, so this stays a human-facing label.
 */
enum LeadSource: string
{
    case WebForm = 'web_form';
    case Referral = 'referral';
    case ColdCall = 'cold_call';
    case Email = 'email';
    case Event = 'event';
    case Advertising = 'advertising';
    case SocialMedia = 'social_media';
    case Partner = 'partner';
    case Chat = 'chat';
    // Meta's channels, added in 12.3. Separate cases rather than folded into
    // SocialMedia or Advertising, because the whole point of the phase is
    // telling them apart: a Lead Ads submission, a Messenger conversation and
    // a WhatsApp message are three different costs and three different
    // follow-ups.
    case FacebookLeadAds = 'facebook_lead_ads';
    case FacebookMessenger = 'facebook_messenger';
    case WhatsApp = 'whatsapp';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WebForm => 'Website form',
            self::Referral => 'Referral',
            self::ColdCall => 'Cold call',
            self::Email => 'Email',
            self::Event => 'Event or trade show',
            self::Advertising => 'Advertising',
            self::SocialMedia => 'Social media',
            self::Partner => 'Partner',
            self::Chat => 'Website chat',
            self::FacebookLeadAds => 'Facebook Lead Ads',
            self::FacebookMessenger => 'Facebook Messenger',
            self::WhatsApp => 'WhatsApp',
            self::Other => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::WebForm => 'cyan',
            self::Referral => 'emerald',
            self::ColdCall => 'orange',
            self::Email => 'blue',
            self::Event => 'violet',
            self::Advertising => 'fuchsia',
            self::SocialMedia => 'rose',
            self::Partner => 'teal',
            self::Chat => 'indigo',
            // The colour says what kind of thing it is, so these share with
            // the generic sources they are a specific case of.
            self::FacebookLeadAds => 'fuchsia',
            self::FacebookMessenger => 'indigo',
            self::WhatsApp => 'emerald',
            self::Other => 'slate',
        };
    }

    /**
     * Whether this source is one Meta produced, which is what decides whether a
     * CRM outcome can be reported back to it through the Conversions API.
     */
    public function isFromMeta(): bool
    {
        return match ($this) {
            self::FacebookLeadAds, self::FacebookMessenger, self::WhatsApp => true,
            default => false,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $source) {
            $options[$source->value] = $source->label();
        }

        return $options;
    }
}
