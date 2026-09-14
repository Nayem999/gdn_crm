<?php

namespace App\Domain\Install\DTOs;

/**
 * Everything the wizard collected, in one value.
 *
 * The mail credentials are a plain map of registry key to value because the
 * provider decides which keys exist. They are handed straight to
 * SaveSettingsAction, which is what encrypts the secret ones — nothing here
 * stores or logs them.
 *
 * @phpstan-type MailValues array<string, mixed>
 */
readonly class InstallData
{
    /**
     * @param  array<string, mixed>  $mail  Keys within the `mail` settings group.
     */
    public function __construct(
        public string $companyName,
        public string $timezone,
        public string $currency,
        public int $fiscalYearStartMonth,
        public string $adminName,
        public string $adminEmail,
        public string $adminPassword,
        public array $mail = [],
    ) {}
}
