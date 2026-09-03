<?php

namespace App\Domain\Notifications\Enums;

enum NotificationChannel: string
{
    case InApp = 'in_app';
    case Email = 'email';
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::InApp => 'In-app',
            self::Email => 'Email',
            self::Sms => 'SMS',
            self::WhatsApp => 'WhatsApp',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::InApp => 'bell',
            self::Email => 'mail',
            self::Sms => 'message-square',
            self::WhatsApp => 'message-circle',
        };
    }

    /**
     * Whether this channel's template carries a subject line as well as a body.
     */
    public function hasSubject(): bool
    {
        return match ($this) {
            self::Email, self::InApp => true,
            self::Sms, self::WhatsApp => false,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $channel) {
            $options[$channel->value] = $channel->label();
        }

        return $options;
    }
}
