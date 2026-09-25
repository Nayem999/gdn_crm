<?php

namespace Database\Seeders;

use App\Domain\Reports\Models\Report;
use App\Domain\Reports\StandardReports;
use Illuminate\Database\Seeder;

/**
 * The reports every installation starts with.
 *
 * Idempotent, and it never overwrites: a company may have edited a built-in to
 * suit itself, and a seeder that undid that on every deploy is one nobody dares
 * run.
 */
class StandardReportsSeeder extends Seeder
{
    public function run(): void
    {
        $before = Report::query()->where('is_standard', true)->count();
        // Upgrades only the built-ins nobody has edited, then installs any the
        // release added — so re-running the seeder on upgrade is still safe.
        $upgraded = StandardReports::upgrade();
        $created = Report::query()->where('is_standard', true)->count() - $before;

        $this->command?->info($created.' standard '.str('report')->plural($created).' installed, '.$upgraded.' upgraded.');
    }
}
