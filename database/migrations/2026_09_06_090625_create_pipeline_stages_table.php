<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The checkpoints along one pipeline.
 *
 * `key` rather than the id is what a deal stores, so renaming a stage does not
 * rewrite every deal that sits in it, and the seeded default pipeline can use
 * the DealStage values a deal created in 2.6 already holds. It is unique within
 * a pipeline and never changes once set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_id')->constrained()->cascadeOnDelete();

            $table->string('key', 64);
            $table->string('name');
            $table->string('color', 20)->default('slate');

            // How likely a deal in this stage is to close, 0-100. The weighted
            // value in 3.2 reads it from here.
            $table->unsignedTinyInteger('probability')->default(0);

            // Whether reaching this stage ends the deal, and which way. Won and
            // lost are stages rather than a separate flag on the deal, so a
            // board column and a closed outcome are the same thing.
            $table->string('outcome', 10)->default('open');

            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->unique(['pipeline_id', 'key']);
            // The board reads one pipeline's stages in order.
            $table->index(['pipeline_id', 'position', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_stages');
    }
};
