<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The minimum a deal needs to exist, because lead conversion creates one.
 *
 * Deals are a Phase 3 module: pipelines and configurable stages (3.1), the full
 * record with products and win/loss reasons (3.2), the board (3.3) and stage
 * history (3.4) all build on this. `stage` is a DealStage enum for now, which
 * 3.1 turns into the default pipeline rather than replaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // A deal is always for an organisation; the person is who to talk to.
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            // Where it came from, kept so the lead's history stays joined up.
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('value', 15, 2)->nullable();
            $table->date('expected_close_date')->nullable();
            $table->string('stage', 32)->default('new');
            $table->text('description')->nullable();

            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // The board groups by stage and the list sorts by newest.
            $table->index(['stage', 'created_at']);
            $table->index(['owner_id', 'stage']);
            $table->index('expected_close_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
