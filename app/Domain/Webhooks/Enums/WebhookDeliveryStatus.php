<?php

namespace App\Domain\Webhooks\Enums;

enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Trying',
            self::Delivered => 'Delivered',
            self::Failed => 'Given up',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Delivered => 'emerald',
            self::Failed => 'rose',
        };
    }
}
