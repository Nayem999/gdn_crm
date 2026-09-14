<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * What every installation needs before anybody logs in: the permission
 * catalogue and the Super Admin role, a deals pipeline, the lead scoring rules,
 * and the standard reports.
 *
 * Kept apart from DatabaseSeeder so the installation wizard can run exactly
 * this and nothing else — DatabaseSeeder also makes a test user, which is the
 * last thing a real install wants.
 *
 * Every seeder it calls is idempotent, so this is safe to re-run and is how a
 * newly added permission or standard report reaches an existing installation.
 */
class BaselineSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(LeadScoringRulesSeeder::class);
        $this->call(PipelinesSeeder::class);
        $this->call(StandardReportsSeeder::class);
    }
}
