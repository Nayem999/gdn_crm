<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A saved report: a name, and the question it asks.
 *
 * The question is a JSON definition of **keys** — source, dimensions, measures,
 * filters — never SQL and never column names. That is what makes a stored
 * report safe to run: the engine resolves every key against the source registry
 * and drops what it does not recognise, so a row edited in the database by hand
 * still cannot make the runner select something it was not offered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('description')->nullable();

            // Denormalised out of the definition so the list can group and
            // filter by it without decoding every row's JSON.
            $table->string('source', 64);

            $table->json('definition');

            // 10.3 picks this up. Held here rather than in the definition
            // because how a report is drawn is a presentation choice, and
            // changing it should not look like changing the question.
            $table->string('chart_type', 32)->default('table');

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            // Shared reports are visible to everybody who may read reports.
            // A private one is its owner's alone, whatever their access level —
            // "I am still working on this" is a real state.
            $table->boolean('is_shared')->default(false);

            // Set by the standard-report seeder. A built-in cannot be removed,
            // because half the application links to it.
            $table->boolean('is_standard')->default(false);
            $table->string('slug')->nullable()->unique();

            $table->timestamp('last_run_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_shared', 'name']);
            $table->index(['owner_id', 'name']);
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
