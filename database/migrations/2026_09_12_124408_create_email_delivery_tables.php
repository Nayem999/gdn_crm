<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What we sent, and what the provider said happened to it afterwards.
 *
 * **Two tables, because they answer two questions.** `email_messages` is the
 * current state of one message — the row a person looks at when a customer says
 * they never got the quote. `email_events` is the history that produced that
 * state, which is what you need when the current state is surprising ("it says
 * bounced, but it also says it was opened twice").
 *
 * Collapsing them would mean either losing the history or re-deriving the state
 * on every read of a table that only grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();

            $table->string('provider', 24);

            /**
             * The provider's own id for the message.
             *
             * This is the join to everything that arrives later — every webhook
             * identifies the message by it and by nothing else. Nullable
             * because a provider can accept a message without naming it, and
             * indexed with the provider because two providers' id spaces are
             * unrelated.
             */
            $table->string('message_id', 191)->nullable();

            $table->string('to_email', 191);
            $table->string('to_name')->nullable();
            $table->string('subject')->nullable();

            $table->string('status', 24);

            // Counts rather than a single flag: "opened once" and "opened
            // eleven times" are different facts about a customer, and the
            // second one is the interesting one.
            $table->unsignedInteger('open_count')->default(0);
            $table->unsignedInteger('click_count')->default(0);

            $table->dateTime('sent_at');
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('opened_at')->nullable();
            $table->dateTime('clicked_at')->nullable();
            $table->dateTime('failed_at')->nullable();

            // Why it bounced, in the provider's words.
            $table->text('reason')->nullable();

            // The notification this message came from, when it came from one.
            $table->foreignId('notification_log_id')->nullable()->constrained('notification_logs')->nullOnDelete();

            // The CRM record it concerns — a quote, a contact, a deal. Filled
            // in by whatever sent the message; 7.8 reads it to build one
            // timeline across every channel.
            $table->nullableMorphs('related');

            $table->timestamps();

            // One row per recipient per message. A message to three people
            // that bounces for one of them is three facts, and a single row
            // could only hold the last of them. The provider is part of the key
            // because two providers' id spaces are unrelated.
            $table->unique(['provider', 'message_id', 'to_email'], 'email_messages_unique');
            $table->index(['status', 'id']);
            $table->index(['to_email', 'id']);
            $table->index('sent_at');
        });

        Schema::create('email_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('email_message_id')->constrained()->cascadeOnDelete();

            $table->string('type', 24);

            // When the provider says it happened, which is not when we heard
            // about it — a webhook can be minutes late, or replayed days later.
            $table->dateTime('occurred_at');

            // For a click.
            $table->text('url')->nullable();
            // For a bounce or a complaint.
            $table->text('reason')->nullable();

            /**
             * What the provider actually sent.
             *
             * Kept because the parsers are the part most likely to be wrong
             * about a provider we have not seen a real payload from, and
             * without the original there is nothing to correct them against.
             */
            $table->json('payload')->nullable();

            /**
             * A fingerprint of the event, so a redelivered webhook is ignored.
             *
             * Providers retry, sometimes for days, and every one of these
             * events is one a naive handler would count twice — an open count
             * that climbs on its own is worse than no open count.
             */
            $table->string('signature', 64)->unique();

            $table->timestamps();

            $table->index(['email_message_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_events');
        Schema::dropIfExists('email_messages');
    }
};
