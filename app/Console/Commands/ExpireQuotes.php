<?php

namespace App\Console\Commands;

use App\Domain\Sales\Actions\ExpireQuotesAction;
use Illuminate\Console\Command;

/**
 * Lapses the quotes whose validity has run out.
 *
 * Daily rather than every minute: validity is a date, so a quote cannot expire
 * part-way through a day, and sweeping every minute would be the same indexed
 * query answering "nothing" fourteen hundred times.
 */
class ExpireQuotes extends Command
{
    protected $signature = 'quotes:expire';

    protected $description = 'Mark sent quotes whose validity date has passed as expired';

    public function handle(ExpireQuotesAction $expire): int
    {
        $count = $expire();

        $this->info($count.' '.str('quote')->plural($count).' expired.');

        return self::SUCCESS;
    }
}
