<?php

namespace App\Domain\Meta\Enums;

/**
 * What state a Meta connection is in.
 *
 * The distinction that matters is between **disconnected** and **needs
 * attention**. A disconnected account is one somebody chose to remove; one
 * whose token expired or whose permission was revoked is still ours — the
 * pages, the forms and every lead that came through them are still here, and
 * the fix is to re-authorise, not to start again. Collapsing the two would
 * throw away the second case's history to describe the first.
 */
enum MetaConnectionStatus: string
{
    case Connected = 'connected';
    case NeedsReauthorisation = 'needs_reauthorisation';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::NeedsReauthorisation => 'Needs reconnecting',
            self::Disconnected => 'Disconnected',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Connected => 'emerald',
            self::NeedsReauthorisation => 'amber',
            self::Disconnected => 'slate',
        };
    }

    /**
     * What an administrator should do about it, in words they can act on.
     */
    public function guidance(): ?string
    {
        return match ($this) {
            self::Connected => null,
            self::NeedsReauthorisation => 'Meta is no longer accepting this connection. Reconnect it to carry on receiving leads and messages.',
            self::Disconnected => 'This connection was removed. Connect again to start receiving leads and messages.',
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
