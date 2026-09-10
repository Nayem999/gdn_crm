<?php

namespace App\Console\Commands;

use App\Domain\Activities\Actions\GenerateRecurringActivitiesAction;
use Illuminate\Console\Command;

/**
 * Rolls the recurrence window forward.
 *
 * Occurrences are materialised for the next GenerateRecurringActivitiesAction::
 * HORIZON_DAYS days, so a series with no end date needs this to keep producing.
 * It is idempotent, so running it twice, or by hand, is harmless.
 */
class GenerateRecurringActivities extends Command
{
    protected $signature = 'activities:generate-recurrences';

    protected $description = 'Materialise upcoming occurrences of every repeating activity';

    public function handle(GenerateRecurringActivitiesAction $generate): int
    {
        $created = $generate();

        $this->info($created.' '.str('occurrence')->plural($created).' created.');

        return self::SUCCESS;
    }
}
