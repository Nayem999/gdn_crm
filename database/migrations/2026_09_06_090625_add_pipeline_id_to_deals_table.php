<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which pipeline a deal is being worked along.
 *
 * Nullable, and null means the default pipeline: deals created in 2.6 predate
 * pipelines existing, and backfilling them here would bake the seeded default's
 * id into a migration. Deal::pipeline() resolves null at read time instead.
 *
 * `stage` stays a string key rather than becoming a foreign key to
 * pipeline_stages. That is what lets a stage be renamed without rewriting every
 * deal, and it keeps 2.6's DealStage values valid against the seeded default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->foreignId('pipeline_id')
                ->nullable()
                ->after('lead_id')
                ->constrained()
                ->nullOnDelete();

            // The board reads one pipeline's deals grouped by stage.
            $table->index(['pipeline_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex(['pipeline_id', 'stage']);
            $table->dropConstrainedForeignId('pipeline_id');
        });
    }
};
