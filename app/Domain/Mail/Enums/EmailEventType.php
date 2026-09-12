<?php

namespace App\Domain\Mail\Enums;

/**
 * One thing a provider told us happened to a message.
 *
 * Deliberately fewer cases than the providers have between them. Mailgun's
 * "permanent failure", Postmark's "HardBounce", Brevo's "hard_bounce" and
 * SendGrid's "bounce" are one fact with four names, and the parsers are where
 * that translation belongs — not in every screen and report that reads the log.
 */
enum EmailEventType: string
{
    case Delivered = 'delivered';
    case Opened = 'opened';
    case Clicked = 'clicked';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Delivered => 'Delivered',
            self::Opened => 'Opened',
            self::Clicked => 'Link clicked',
            self::Bounced => 'Bounced',
            self::Complained => 'Marked as spam',
            self::Failed => 'Failed',
        };
    }

    public function status(): EmailStatus
    {
        return match ($this) {
            self::Delivered => EmailStatus::Delivered,
            self::Opened => EmailStatus::Opened,
            self::Clicked => EmailStatus::Clicked,
            self::Bounced => EmailStatus::Bounced,
            self::Complained => EmailStatus::Complained,
            self::Failed => EmailStatus::Failed,
        };
    }
}
