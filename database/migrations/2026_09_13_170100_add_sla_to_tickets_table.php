<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The clock, on the ticket it is running against.
 *
 * Denormalised on purpose. "Which tickets are about to breach" is a sweep that
 * runs every minute against every open ticket, and computing a due time from a
 * policy, a priority and a pause history in SQL would make that sweep a join
 * nobody can index. The due columns are written when the clock is set or
 * resumed, and the sweep is then one indexed range scan.
 *
 * Every column here is nullable, so none of them collects MySQL's implicit
 * ON UPDATE CURRENT_TIMESTAMP — see .ai/rules/migrations.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Nullable: a ticket raised before any policy existed, or under one
            // that has since been removed, still has to open.
            $table->foreignId('sla_policy_id')->nullable()->after('source')->constrained()->nullOnDelete();

            $table->timestamp('first_response_due_at')->nullable()->after('sla_policy_id');
            $table->timestamp('resolution_due_at')->nullable()->after('first_response_due_at');

            // When somebody on our side first said something to the customer.
            // Not the same as "a comment exists": an internal note is not an
            // answer, and neither is the customer writing again.
            $table->timestamp('first_responded_at')->nullable()->after('resolution_due_at');

            // The pause. paused_at is when the current hold started, or null
            // when the clock is running; paused_seconds is what earlier holds
            // already cost, kept so a report can say how long a ticket spent
            // waiting on us rather than on the customer.
            $table->timestamp('sla_paused_at')->nullable()->after('first_responded_at');
            $table->unsignedInteger('sla_paused_seconds')->default(0)->after('sla_paused_at');

            // Stamped once each, so a sweep every minute does not send the same
            // warning fourteen hundred times.
            $table->timestamp('response_warned_at')->nullable()->after('sla_paused_seconds');
            $table->timestamp('response_breached_at')->nullable()->after('response_warned_at');
            $table->timestamp('resolution_warned_at')->nullable()->after('response_breached_at');
            $table->timestamp('resolution_breached_at')->nullable()->after('resolution_warned_at');

            $table->timestamp('escalated_at')->nullable()->after('resolution_breached_at');

            // The sweep's two queries: open tickets whose due time has arrived.
            $table->index(['first_response_due_at', 'first_responded_at'], 'tickets_sla_response_index');
            $table->index(['resolution_due_at', 'resolved_at'], 'tickets_sla_resolution_index');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_sla_response_index');
            $table->dropIndex('tickets_sla_resolution_index');
            $table->dropConstrainedForeignId('sla_policy_id');
            $table->dropColumn([
                'first_response_due_at',
                'resolution_due_at',
                'first_responded_at',
                'sla_paused_at',
                'sla_paused_seconds',
                'response_warned_at',
                'response_breached_at',
                'resolution_warned_at',
                'resolution_breached_at',
                'escalated_at',
            ]);
        });
    }
};
