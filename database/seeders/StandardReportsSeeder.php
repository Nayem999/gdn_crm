<?php

namespace Database\Seeders;

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
        $created = StandardReports::install();

        $this->command?->info($created.' standard '.str('report')->plural($created).' installed.');
    }
}
