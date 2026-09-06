<?php

namespace App\Domain\Shared\Duplicates;

use App\Domain\Settings\NumberFormat;
use App\Models\User;

/**
 * The parts of displayValue() every module needs: blanks, owners, money.
 */
trait FormatsMergeValues
{
    public const BLANK = '—';

    protected function blankOr(mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return self::BLANK;
        }

        return is_scalar($value) ? (string) $value : self::BLANK;
    }

    protected function ownerName(mixed $ownerId): string
    {
        if (! is_numeric($ownerId)) {
            return self::BLANK;
        }

        return User::query()->whereKey((int) $ownerId)->value('name') ?? self::BLANK;
    }

    protected function money(mixed $value): string
    {
        if ($value === null || ! is_numeric($value)) {
            return self::BLANK;
        }

        return NumberFormat::format((float) $value, 0);
    }
}
