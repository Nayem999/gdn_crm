<?php

namespace App\Domain\Support\Enums;

/**
 * How the ticket reached us.
 *
 * Worth a column of its own because this application already receives work from
 * three directions — somebody typing it in, inbound email (7.6) and the data
 * gateway (Phase 8) — and a ticket that cannot say which is one nobody can
 * trace back to the conversation it came from.
 */
enum TicketSource: string
{
    case Manual = 'manual';
    case Email = 'email';
    case Portal = 'portal';
    case Phone = 'phone';
    case Chat = 'chat';
    case Integration = 'integration';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Entered by an agent',
            self::Email => 'Email',
            self::Portal => 'Customer portal',
            self::Phone => 'Phone',
            self::Chat => 'Chat',
            self::Integration => 'Another system',
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
