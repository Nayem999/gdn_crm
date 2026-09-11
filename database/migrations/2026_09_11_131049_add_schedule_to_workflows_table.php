<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a scheduled workflow runs.
 *
 * 5.1 gave the schema a `scheduled` trigger and nothing to configure it with —
 * the date trigger's field and offset say nothing about a recurrence. A cron
 * expression is what the rest of the application already understands (Laravel's
 * scheduler is built on the same parser), so a workflow stores one rather than
 * a bespoke frequency vocabulary that would need translating anyway.
 *
 * It is evaluated against the **office clock**, not UTC: somebody writing
 * "every weekday at 9" means nine o'clock where they work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->string('schedule_expression', 64)->nullable()->after('date_offset_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn('schedule_expression');
        });
    }
};
