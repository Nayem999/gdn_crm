<?php

namespace App\Domain\Timeline\Communications;

/**
 * The channels a communication can have travelled on.
 *
 * Calls are not here, and that is deliberate: a call in this system is a
 * scheduled activity, and it is already on the timeline through that strand.
 * Listing it here as well would put every call on the page twice.
 */
enum CommunicationChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';
    case Chat = 'chat';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Sms => 'SMS',
            self::WhatsApp => 'WhatsApp',
            self::Chat => 'Chat',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Email => 'lucide-mail',
            self::Sms => 'lucide-message-square',
            self::WhatsApp => 'lucide-message-circle',
            self::Chat => 'lucide-messages-square',
        };
    }
}
