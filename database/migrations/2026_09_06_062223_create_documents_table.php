<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A file attached to a record, with the things a file alone cannot carry: who
 * put it there, what it is for, and which record it belongs to.
 *
 * The bytes live in medialibrary on the private disk. A row here rather than
 * bare media on the record gives the file a title, an uploader with a real
 * foreign key, and a model a policy can be written against — media rows carry
 * no authorization of their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->morphs('documentable');

            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->string('description', 500)->nullable();

            $table->timestamps();

            $table->index(['documentable_type', 'documentable_id', 'created_at', 'id'], 'documents_timeline_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
