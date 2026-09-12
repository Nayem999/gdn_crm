<?php

namespace App\Domain\Messaging;

class WhatsAppSettingsTester extends MessagingSettingsTester
{
    protected function channel(): string
    {
        return MessagingProviders::WHATSAPP;
    }
}
