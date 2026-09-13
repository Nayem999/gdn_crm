<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The conversation on a ticket.
 *
 * Its own table rather than a note on the timeline, because a ticket comment is
 * a different thing from an internal note: a reply is **sent to the customer**,
 * and the two must not be one row type that somebody can confuse. `is_internal`
 * is the whole distinction, and the notification recipients read it.
 *
 * `author_id` is nullable and so is the customer's name, because a comment can
 * come from either side: an agent (a user) or the customer (a contact, who is
 * not a user and may not exist as a record at all when a reply arrives by
 * email in 9.x).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_comments', function (Blueprint $table) {
            $table->id();

            // Removing a ticket takes its conversation with it; the ticket
            // itself is only ever soft-deleted, so this cascade fires only on
            // a real, deliberate purge.
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();

            // Nullable so removing a user does not take their replies with
            // them, and so a customer's own reply has somewhere to live.
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            // Who said it, when it was not one of our users. Stored rather than
            // read back through contact_id: a customer's name at the time they
            // said it is part of the record.
            $table->string('author_name')->nullable();

            $table->text('body');

            // An internal comment is never sent to the customer and never
            // appears on anything customer-facing. Default false, because the
            // dangerous mistake is a note the customer was not meant to see,
            // not a reply they were.
            $table->boolean('is_internal')->default(false);

            // Whether the customer wrote it, rather than an agent. Not the same
            // question as "author_id is null" — a removed user's reply also has
            // no author, and that one was ours.
            $table->boolean('from_customer')->default(false);

            $table->timestamps();
            $table->softDeletes();

            // The ticket page reads one ticket's conversation oldest first, and
            // id is the tiebreaker for same-second rows.
            $table->index(['ticket_id', 'created_at', 'id'], 'ticket_comments_thread_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_comments');
    }
};
