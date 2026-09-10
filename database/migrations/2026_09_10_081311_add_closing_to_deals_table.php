<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a deal ended, and when.
 *
 * There is no `is_won` column: whether a deal is closed is decided by the
 * outcome of the stage it sits in (3.1), so a flag here could disagree with the
 * board. What cannot be derived is the *reason*, and that is what this adds.
 *
 * `closed_at` is stamped by MoveDealStageAction when a deal first reaches a
 * closing stage, and cleared when it is reopened — it is the basis of the
 * cycle-time reporting in Phase 10.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('expected_close_date');

            // A DealCloseReason value. Nullable because an open deal has none,
            // and because a deal can be closed before anybody records why.
            $table->string('close_reason', 32)->nullable()->after('closed_at');
            $table->text('close_notes')->nullable()->after('close_reason');

            // "What did we win and lose last quarter, and why" is the report
            // this exists for.
            $table->index(['close_reason', 'closed_at']);
            $table->index('closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex(['close_reason', 'closed_at']);
            $table->dropIndex(['closed_at']);
            $table->dropColumn(['closed_at', 'close_reason', 'close_notes']);
        });
    }
};
