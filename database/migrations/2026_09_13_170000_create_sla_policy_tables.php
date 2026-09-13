<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What we promise a customer, and how long we have.
 *
 * Two tables rather than one with a column per priority: a target is
 * "for this priority, answer within X and fix within Y", and four pairs of
 * columns on the policy would have to be renumbered the day somebody adds a
 * fifth priority. A row per priority also lets a policy leave one out, which
 * means "no promise at that priority" rather than zero minutes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();

            // The one applied to a ticket that matches nothing else. Enforced in
            // the action rather than by a unique index, because "exactly one
            // default" is a rule about the live rows and this table
            // soft-deletes.
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            // How far through a target counts as a warning. Per policy, because
            // "tell me at 80%" on a four-hour promise and on a five-day one are
            // different amounts of warning, and a desk knows which it wants.
            $table->unsignedTinyInteger('warn_at_percent')->default(80);

            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });

        Schema::create('sla_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sla_policy_id')->constrained()->cascadeOnDelete();

            // The stored rank from TicketPriority, the same integer the tickets
            // table holds.
            $table->unsignedTinyInteger('priority');

            // Nullable means "no promise": a desk may guarantee a first reply on
            // every ticket but a resolution time only on the urgent ones.
            $table->unsignedInteger('first_response_minutes')->nullable();
            $table->unsignedInteger('resolution_minutes')->nullable();

            $table->timestamps();

            // One target per priority per policy. Two would make "how long have
            // we got" ambiguous, which is the one thing this table exists to
            // answer.
            $table->unique(['sla_policy_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_targets');
        Schema::dropIfExists('sla_policies');
    }
};
