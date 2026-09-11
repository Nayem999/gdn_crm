<?php

namespace App\Domain\Leads\Capture;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * The "when was this form drawn" value, signed so it cannot be back-dated.
 *
 * A plain hidden timestamp is worth nothing: a bot posts whatever it likes.
 * Encrypting it with the application key means the value can only have come
 * from a page this application rendered.
 */
final class CaptureTimestamp
{
    public static function issue(): string
    {
        return Crypt::encryptString((string) now()->getTimestamp());
    }

    /**
     * The moment a form was rendered, or null when the value did not come from
     * this application.
     */
    public static function read(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            $timestamp = (int) Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }

        return $timestamp > 0 ? Carbon::createFromTimestamp($timestamp) : null;
    }
}
