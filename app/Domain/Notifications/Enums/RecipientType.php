<?php

namespace App\Domain\Notifications\Enums;

/**
 * Who a notification is aimed at. The matrix is one row per event per recipient
 * type per channel, so an admin can tell the customer but not the agent.
 */
enum RecipientType: string
{
    case Customer = 'customer';
    case AssignedAgent = 'assigned_agent';
    case Admin = 'admin';
    case Watcher = 'watcher';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::AssignedAgent => 'Assigned agent',
            self::Admin => 'Administrator',
            self::Watcher => 'Watcher',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Customer => 'The contact the record belongs to.',
            self::AssignedAgent => 'Whoever the record is assigned to.',
            self::Admin => 'People who administer this area.',
            self::Watcher => 'Anyone following the record.',
        };
    }
}
