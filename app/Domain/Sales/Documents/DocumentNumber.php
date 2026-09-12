<?php

namespace App\Domain\Sales\Documents;

use Illuminate\Support\Facades\DB;

/**
 * The next number for a kind of document.
 *
 * Taken from a counter row under a lock, not from `max(number) + 1`: that query
 * is a race two documents created in the same second both win, and they then
 * claim the same number. Invoices are expected to be sequential and gap-free,
 * and "we skipped 1043" is a conversation with an accountant rather than a bug
 * report.
 *
 * Numbers are scoped by year, because that is how everybody reads them and
 * because a counter that never resets eventually prints a number nobody can
 * say aloud.
 */
final class DocumentNumber
{
    /**
     * @param  string  $kind  e.g. "quote"
     * @param  string  $prefix  e.g. "Q"
     */
    public static function next(string $kind, string $prefix, ?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $key = $kind.':'.$year;

        $value = DB::transaction(function () use ($key): int {
            // firstOrCreate outside the lock would race on the unique key; this
            // way the insert either wins or the row is there to lock.
            DB::table('document_sequences')->insertOrIgnore([
                'key' => $key,
                'next_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('document_sequences')->where('key', $key)->lockForUpdate()->first();
            $next = (int) ($row->next_value ?? 1);

            DB::table('document_sequences')
                ->where('key', $key)
                ->update(['next_value' => $next + 1, 'updated_at' => now()]);

            return $next;
        });

        return sprintf('%s-%d-%04d', $prefix, $year, $value);
    }
}
