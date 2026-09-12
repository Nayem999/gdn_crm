<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website chat, and the leads it produces.
 *
 * **A chat widget is a kind of lead capture form, not a new thing.** It has
 * exactly the same four properties that matter: a token in a URL, an owner who
 * gets the lead, a source to record, and a switch to turn it off. Giving it its
 * own table would have meant a second copy of all of that, a second management
 * screen, and two places to remember when a rule about public capture changes.
 * So `kind` says which it is, and everything else is shared.
 *
 * The conversation is stored even when it never becomes a lead. Somebody who
 * asks a question and leaves without giving an address is not a lead, but the
 * question is still worth having.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_capture_forms', function (Blueprint $table) {
            $table->string('kind', 16)->default('form')->after('name');
        });

        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();

            // Which widget it came through. Cascades: a deleted widget's
            // conversations have no meaning on their own.
            $table->foreignId('lead_capture_form_id')->constrained()->cascadeOnDelete();

            /**
             * The visitor's own handle on the conversation, minted by the
             * widget and sent with every message.
             *
             * It is what makes a conversation a conversation rather than a
             * series of unrelated messages, and it is not a secret: knowing one
             * lets somebody add to that conversation, which is the same thing
             * the visitor can do.
             */
            $table->uuid('session_id')->unique();

            $table->string('visitor_name')->nullable();
            $table->string('visitor_email', 191)->nullable();
            $table->string('visitor_phone', 32)->nullable();

            // Where they were when they started typing — frequently the most
            // useful line in the whole conversation.
            $table->text('page_url')->nullable();

            // Null until the visitor says enough for a lead to be worth
            // creating. Nulls out rather than cascades: deleting a lead should
            // not delete the conversation that produced it.
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();

            $table->dateTime('started_at');
            $table->dateTime('last_message_at');

            $table->timestamps();

            $table->index(['lead_capture_form_id', 'last_message_at']);
            $table->index('visitor_email');
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('chat_conversation_id')->constrained()->cascadeOnDelete();

            // Who said it. A string rather than a user id because most of these
            // have no user behind them.
            $table->string('author', 16);

            $table->text('body');

            $table->dateTime('sent_at');

            $table->timestamps();

            $table->index(['chat_conversation_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');

        Schema::table('lead_capture_forms', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
