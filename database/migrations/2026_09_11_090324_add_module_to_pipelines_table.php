<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A pipeline belongs to a module.
 *
 * 3.1 built pipelines for deals alone. The machinery — ordered stages, a key
 * per stage, an outcome, exactly one default — is the same thing a lead's
 * status set or an activity's status set is, so 4.4 gives it a module rather
 * than building a second, near-identical table called `custom_statuses`.
 *
 * Backfilled to 'deals', which is what every existing row is. The default is
 * 'deals' as well, so any code path that creates a pipeline without saying
 * which module still produces the one it used to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipelines', function (Blueprint $table) {
            $table->string('module', 32)->default('deals')->after('id');
        });

        // Explicit rather than relying on the column default, so the intent
        // survives a later change to that default.
        Schema::table('pipelines', function (Blueprint $table) {
            $table->index(['module', 'position', 'id']);
        });

        DB::table('pipelines')->update(['module' => 'deals']);
    }

    public function down(): void
    {
        Schema::table('pipelines', function (Blueprint $table) {
            $table->dropIndex(['module', 'position', 'id']);
            $table->dropColumn('module');
        });
    }
};
