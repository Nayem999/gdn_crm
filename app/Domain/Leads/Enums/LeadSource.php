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
            self::Other => 'slate',
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
