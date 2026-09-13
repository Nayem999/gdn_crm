<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's problem, and what is being done about it.
 *
 * The support module's one table. Comments and replies (9.2), SLA clocks (9.3)
 * and the knowledge base (9.4) each bring their own; this is the record they
 * all hang off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();

            /**
             * The reference a customer quotes on the phone.
             *
             * Nullable only because it is derived from the id, which does not
             * exist until the row does — see Ticket::booted(). Unique, so two
             * tickets can never answer to one number however they were made.
             */
            $table->string('number', 24)->nullable()->unique();

            $table->string('subject');
            $table->text('description')->nullable();

            $table->string('status', 32)->default('new');
            // Ranked rather than named, so ORDER BY means "most urgent first".
            // The same reasoning as ActivityPriority — see .ai/rules/activities.md.
            $table->unsignedTinyInteger('priority')->default(2);
            // Where it came from. Phase 7 already receives email and Phase 8
            // receives deliveries, so a ticket that cannot say which is a
            // ticket nobody can trace back.
            $table->string('source', 32)->default('manual');

            // Who it is for. A ticket is usually raised by a person at an
            // organisation, and either half can be absent — a walk-in with no
            // contact record still gets a ticket.
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();

            // The agent it is assigned to. Visibility follows this column.
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            /**
             * Stamped when the ticket reaches a status that means it is done.
             *
             * Two stamps rather than one: resolved is "we think it is fixed"
             * and closed is "nobody is coming back to it", and support teams
             * are measured on the gap between them.
             */
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The board groups by status; the queue is read newest first.
            $table->index(['status', 'created_at']);
            // "My open tickets, most urgent first" is the query an agent lives in.
            $table->index(['owner_id', 'status', 'priority']);
            $table->index(['account_id', 'status']);
            $table->index('priority');
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
