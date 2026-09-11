<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes `run_count` and `last_run_at`, added in 5.1 and wrong.
 *
 * They looked like cheap bookkeeping and are the most expensive thing in the
 * trigger engine:
 *
 * - **A write on the hottest path.** Every created, updated or deleted record
 *   meant an UPDATE of the workflow row, on top of the insert into the log.
 *   Importing five thousand leads wrote the same row five thousand times.
 * - **Every one of those writes contends on a single row.** Concurrent inserts
 *   against one active workflow serialise behind it.
 * - **`run_count = run_count + 1` read in PHP is a race.** Two workers both read
 *   5 and both write 6, so the number is not even reliably right.
 *
 * And it is duplicated data: `workflow_runs` already *is* the record of what
 * ran and when. A count and a last-run time are one indexed GROUP BY away, which
 * is what 5.8's health dashboard will do — against the truth rather than beside
 * it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn(['run_count', 'last_run_at']);
        });
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->unsignedInteger('run_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
        });
    }
};
