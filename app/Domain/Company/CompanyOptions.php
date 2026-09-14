<?php

namespace App\Domain\Company;

use DateTimeZone;

/**
 * The choices the company profile offers — timezone, currency, fiscal year.
 *
 * Here rather than on the screen that edits them, because the installation
 * wizard asks for exactly the same three on its first step and two lists that
 * drift apart would mean a currency you can install with and then never pick
 * again.
 */
final class CompanyOptions
{
    /**
     * Every zone PHP knows, spelled the way a person reads it.
     *
     * @return array<string, string>
     */
    public static function timezones(): array
    {
        return collect(DateTimeZone::listIdentifiers())
            ->mapWithKeys(fn (string $timezone) => [$timezone => str_replace('_', ' ', $timezone)])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function currencies(): array
    {
        return [
            'USD' => 'USD — US Dollar',
            'EUR' => 'EUR — Euro',
            'GBP' => 'GBP — British Pound',
            'CAD' => 'CAD — Canadian Dollar',
            'AUD' => 'AUD — Australian Dollar',
            'INR' => 'INR — Indian Rupee',
            'BDT' => 'BDT — Bangladeshi Taka',
            'PKR' => 'PKR — Pakistani Rupee',
            'AED' => 'AED — UAE Dirham',
            'SAR' => 'SAR — Saudi Riyal',
            'SGD' => 'SGD — Singapore Dollar',
            'JPY' => 'JPY — Japanese Yen',
            'CNY' => 'CNY — Chinese Yuan',
            'ZAR' => 'ZAR — South African Rand',
            'NGN' => 'NGN — Nigerian Naira',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function months(): array
    {
        return [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
        ];
    }
}
