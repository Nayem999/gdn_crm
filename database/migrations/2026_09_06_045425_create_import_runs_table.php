<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One attempt at importing a file.
 *
 * A large import runs on the queue, so it needs somewhere outside the request
 * to report what happened: which rows landed, which were refused and why. The
 * row-level errors are kept here rather than in a log because the person who
 * uploaded the file is the one who has to fix it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();

            // Matched against ImportRegistry, never turned into a class name.
            $table->string('module', 32);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('original_filename');
            $table->string('path');

            // Column index => field key, as the operator mapped it.
            $table->json('mapping');

            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);

            // Capped: a file where every row fails should not put a megabyte of
            // JSON in the table. failed_rows still carries the true count.
            $table->json('errors')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['module', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
    }
};
