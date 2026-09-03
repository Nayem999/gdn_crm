<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-defined rules for scoring and qualifying leads.
 *
 * A rule is one filter condition plus a meaning: a score rule adds points when
 * a lead matches it, a qualification rule is a requirement the lead must meet
 * before it may be marked Qualified. Both kinds share this table because both
 * are evaluated the same way — through the shared filter engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_scoring_rules', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20)->default('score');
            $table->string('label');

            // The condition, in the same shape FilterCondition uses. `field` is
            // validated against LeadFields::filters() on write and again on
            // evaluation, so a stale rule can never reach an arbitrary column.
            $table->string('field', 64);
            $table->string('operator', 32);
            $table->string('value')->nullable();
            $table->string('second_value')->nullable();
            $table->json('selected')->nullable();

            // Signed: a rule may subtract, e.g. a free-mail address.
            // Ignored for qualification rules, which are pass/fail.
            $table->smallInteger('points')->default(0);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['kind', 'is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_scoring_rules');
    }
};
