<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One conversation model for every channel a customer can message us on.
 *
 * **A channel is a column, not a table.** Messenger, WhatsApp and — if it is
 * ever wanted — Instagram Direct are the same shape: somebody outside sends
 * messages, somebody here answers, and there is a window during which answering
 * is allowed. Three tables would be three inboxes, three sets of assignment
 * rules and three places to fix the same bug; and the brief's own §5 and §15
 * describe the same screen twice, which is the tell.
 *
 * What differs per channel is the **window** and the send call, and both are one
 * `match` each. 12.10 adds WhatsApp by adding cases, not tables.
 *
 * The participant lives in columns here rather than in a `social_participants`
 * table: a conversation has exactly one counterparty, and a join that always
 * returns one row is a join for nothing. Their name and handle are **copied**
 * rather than looked up, for the reason a stage visit copies its stage name —
 * somebody who changes their Facebook display name should not silently rewrite
 * what a conversation from last March says.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_conversations', function (Blueprint $table) {
            $table->id();

            $table->string('channel', 16);

            // Meta's id for the thread. 191 rather than 255: this is half of a
            // unique index, and the pair has to fit whatever row format the
            // server is using.
            $table->string('external_conversation_id', 191);

            // Which of our things it arrived at — the page id for Messenger, the
            // phone number id for WhatsApp. One column because it answers one
            // question, and a business with two pages needs the answer.
            $table->string('channel_account_id', 64)->nullable()->index();

            // Who is on the other end, as they were when they wrote.
            $table->string('participant_external_id', 191)->nullable()->index();
            $table->string('participant_name')->nullable();
            $table->string('participant_handle')->nullable();

            // What this conversation is about in the CRM. Both nullable and both
            // nulled rather than cascaded on delete: deleting a lead must not
            // erase what somebody actually said.
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            // Who is answering it. Nulled on delete, so somebody leaving the
            // company returns their conversations to the queue rather than
            // taking them.
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 16)->default('open');
            $table->unsignedInteger('unread_count')->default(0);

            $table->dateTime('last_message_at')->nullable();
            // When free-form replying stops being allowed. Stored rather than
            // computed at read time because it is **Meta's** rule about a
            // moment, and the inbox has to sort and filter by it — a screen
            // cannot order by a method.
            $table->dateTime('window_expires_at')->nullable();

            $table->timestamps();

            // One thread, once. Meta retries its webhooks and two workers can
            // race the same delivery; this is what makes threading a fact rather
            // than a hope.
            $table->unique(['channel', 'external_conversation_id'], 'social_conversations_thread_unique');
            // "What is on my list" — the inbox's first query.
            $table->index(['assigned_to_id', 'status']);
            // "What is waiting for anybody", newest first.
            $table->index(['status', 'last_message_at']);
            $table->index('lead_id');
            $table->index('contact_id');
        });

        Schema::create('social_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('social_conversation_id')->constrained('social_conversations')->cascadeOnDelete();

            // Copied from the conversation so the unique index below can stand
            // on its own: two channels can legitimately mint the same id, and a
            // constraint that had to join to find out would not be a constraint.
            $table->string('channel', 16);
            $table->string('external_message_id', 191)->nullable();

            $table->string('direction', 16);
            $table->string('type', 16)->default('text');

            $table->text('body')->nullable();
            // Attachments as Meta describes them: type, our stored path once it
            // has been fetched, and the caption. JSON because a message can
            // carry several and none of it is ever filtered on.
            $table->json('media')->nullable();
            // Which approved template was used, when one was. The column that
            // makes "what did we send outside the window" answerable.
            $table->string('template_name')->nullable();

            $table->string('status', 16)->default('received');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('read_at')->nullable();

            // Who here sent it. Null for an inbound message, and null for one an
            // automation sent — restrictOnDelete would make a departing employee
            // undeletable, so this nulls and the message stays.
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('error')->nullable();

            $table->timestamps();

            // One message, once. Nullable on purpose: an outbound message has no
            // id of Meta's until Meta answers, and MySQL allows many NULLs in a
            // unique index — which is exactly the behaviour wanted here.
            $table->unique(['channel', 'external_message_id'], 'social_messages_external_unique');
            // The thread, in order. Every read of a conversation is this query.
            $table->index(['social_conversation_id', 'created_at'], 'social_messages_thread_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_messages');
        Schema::dropIfExists('social_conversations');
    }
};
