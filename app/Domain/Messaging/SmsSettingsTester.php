<?php

namespace App\Domain\Messaging;

class SmsSettingsTester extends MessagingSettingsTester
{
    protected function channel(): string
    {
        return MessagingProviders::SMS;
    }
}
