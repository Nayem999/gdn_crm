<?php

namespace App\Domain\Auth\Enums;

enum LoginEvent: string
{
    case Login = 'login';
    case Failed = 'failed';
    case Logout = 'logout';
    case Lockout = 'lockout';

    public function label(): string
    {
        return match ($this) {
            self::Login => 'Signed in',
            self::Failed => 'Failed sign-in',
            self::Logout => 'Signed out',
            self::Lockout => 'Locked out',
        };
    }

    /**
     * Tailwind colour token used by status chips for this event.
     */
    public function color(): string
    {
        return match ($this) {
            self::Login => 'emerald',
            self::Failed => 'amber',
            self::Logout => 'slate',
            self::Lockout => 'rose',
        };
    }
}
