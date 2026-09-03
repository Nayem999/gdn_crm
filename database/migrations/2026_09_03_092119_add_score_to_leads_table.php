<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The computed score is stored rather than derived on read, so the list can
 * sort and filter by it and the board can show it without re-running every
 * rule for every row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedTinyInteger('score')->default(0)->after('estimated_value');
            $table->timestamp('scored_at')->nullable()->after('score');

            $table->index('score');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['score']);
            $table->dropColumn(['score', 'scored_at']);
        });
    }
};
