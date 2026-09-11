<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where round-robin got to.
 *
 * Round robin is the one assignment strategy that cannot be derived from the
 * data: "whose turn is it" is a fact about the last time it was asked, not
 * about the records. Load-based and territory both read the world and need no
 * memory; this one does.
 *
 * A table rather than the step's own JSON config, because the pointer is
 * **state that moves on every assignment** and the config is a definition
 * somebody edits. Writing a moving number into a config column would mean every
 * assignment counted as an edit in the audit trail, and two concurrent runs
 * would race on a JSON document rather than on a row that can be locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_pointers', function (Blueprint $table) {
            $table->id();

            // Scoped to the step, so two workflows taking turns through the
            // same team do not share a turn and hand two leads to one person.
            $table->string('key', 191)->unique();

            $table->unsignedInteger('position')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_pointers');
    }
};
