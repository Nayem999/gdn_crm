<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_view_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The module key a list screen registers itself under, e.g. "leads".
            $table->string('module');
            $table->string('view_mode')->default('table');
            // Schemaless per-user chrome: which columns show, in what order, and
            // which are pinned left. Never filtered or joined on, which is the
            // only place JSON is allowed.
            $table->json('columns')->nullable();
            $table->json('pinned_columns')->nullable();
            $table->unsignedSmallInteger('per_page')->default(25);
            $table->timestamps();

            $table->unique(['user_id', 'module']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_view_preferences');
    }
};
