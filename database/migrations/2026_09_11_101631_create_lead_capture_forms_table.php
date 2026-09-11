<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A public form that turns a submission into a lead.
 *
 * The only unauthenticated write path in the application, so everything about
 * this table is shaped by that: the public address is a **random token**, not a
 * guessable slug, so a form cannot be found by trying names; a form can be
 * switched off without being deleted, because the URL is out in the world on
 * somebody's website and has to keep resolving to something sensible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_capture_forms', function (Blueprint $table) {
            $table->id();

            // The public address. Random rather than derived from the name: a
            // slug would let anyone enumerate an organisation's forms, and the
            // token is the only thing standing between the internet and a
            // write path.
            $table->string('token', 32)->unique();

            $table->string('name');
            $table->text('description')->nullable();

            /**
             * Which fields the form shows, in order, and which are required.
             * JSON because nothing filters on it — it is read whole, for one
             * form, when the page renders.
             */
            $table->json('fields');

            // Where a new lead lands. Both are configured rather than guessed:
            // an unassigned lead is how a captured lead quietly goes nowhere.
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('source', 32)->nullable();

            $table->string('submit_label')->default('Send');
            $table->text('success_message')->nullable();
            // Where to send the browser afterwards. Validated as a URL on save
            // and rendered as a redirect, never echoed into the page.
            $table->string('redirect_url')->nullable();

            $table->boolean('is_active')->default(true);

            // How many submissions it has taken, and when the last one landed —
            // the two things somebody checks when asking "is this working".
            $table->unsignedInteger('submission_count')->default(0);
            $table->timestamp('last_submitted_at')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_capture_forms');
    }
};
