<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email that arrived, rather than email that left.
 *
 * **The body is stored here, unlike anywhere else in the logs.** The delivery
 * log deliberately keeps no copy of what was said, because it only has to say
 * what happened. An inbound message is different: the reply *is* the record —
 * it is what the customer said, and a timeline entry saying "they replied" with
 * no reply in it is worth nothing.
 *
 * Identity is the RFC Message-ID, which is what makes a second delivery of the
 * same message a no-op. IMAP will hand the same message over again after any
 * interruption, so that is not an edge case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_messages', function (Blueprint $table) {
            $table->id();

            /**
             * The sender's own Message-ID.
             *
             * Unique, and the only thing that makes a re-sync idempotent. A
             * message without one is given a synthetic id built from its
             * contents — rare, but a mail client that omits it must not mean
             * one row per sync.
             */
            $table->string('message_id', 191)->unique();

            // Threading, as the sender sees it.
            $table->string('in_reply_to', 191)->nullable()->index();
            $table->text('references')->nullable();

            $table->string('from_email', 191)->index();
            $table->string('from_name')->nullable();
            $table->string('to_email', 191)->nullable();

            $table->string('subject')->nullable();
            $table->longText('body')->nullable();

            /**
             * Where in the mailbox it came from.
             *
             * The UID is only meaningful together with the folder's
             * UIDVALIDITY: a mailbox that renumbers — a restore, a rebuild —
             * bumps that value, and every UID we hold becomes meaningless. The
             * sync starts again from the beginning when it changes, which is
             * the only safe reading.
             */
            $table->string('folder', 191);
            $table->unsignedBigInteger('uid');
            $table->unsignedBigInteger('uid_validity');

            // What it was matched to — a contact, a lead, and in phase 9 a
            // ticket. Null means nobody recognised the sender.
            $table->nullableMorphs('related');

            // The outbound message it is a reply to, when it is one.
            $table->foreignId('email_message_id')->nullable()->constrained('email_messages')->nullOnDelete();

            $table->dateTime('received_at');

            $table->timestamps();

            $table->index(['folder', 'uid_validity', 'uid']);
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_messages');
    }
};
