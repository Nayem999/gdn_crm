<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What somebody wrote about a record.
 *
 * Polymorphic rather than a column per module: a note is the same thing on a
 * lead, a contact and an account, and Phase 3 adds deals and activities to the
 * same timeline without another table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->morphs('notable');

            // Nullable so removing a user does not take their notes with them;
            // the timeline falls back to "somebody who has since left".
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('body');

            $table->timestamps();

            // The timeline reads one record's notes newest first, and id is the
            // tiebreaker that keeps same-second rows in a stable order.
            $table->index(['notable_type', 'notable_id', 'created_at', 'id'], 'notes_timeline_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
