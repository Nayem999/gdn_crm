<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A route a deal is worked along.
 *
 * More than one because different business is sold differently — a renewal and
 * a new-logo deal do not pass the same checkpoints. Exactly one is the default,
 * which is what a deal gets when nobody chooses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipelines', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description', 500)->nullable();

            // Enforced in SetDefaultPipelineAction rather than by a unique
            // index: "exactly one true, any number of false" is not something a
            // unique constraint expresses, and a partial index is not portable.
            $table->boolean('is_default')->default(false);

            $table->unsignedInteger('position')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique('name');
            $table->index(['position', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipelines');
    }
};
