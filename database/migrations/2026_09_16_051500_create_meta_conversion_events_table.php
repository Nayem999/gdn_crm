<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What this CRM has told Meta about the leads Meta sent it.
 *
 * A row per outcome reported, kept whether it succeeded or not. Three things
 * make the table worth having rather than firing and forgetting:
 *
 * **`event_id` is unique, and it is the whole of the idempotency.** Meta
 * de-duplicates on it too, but only for 48 hours and only as a courtesy; the
 * constraint here is what stops a deal that was won, reopened and won again
 * from being counted twice in the figures the advertising is optimised against.
 * Double-counted conversions do not merely misreport — they teach Meta's
 * delivery to chase the wrong people.
 *
 * **The response is stored.** Meta answers a rejected event with 200 and a
 * message inside the body, so "did that work" is a question only the stored
 * answer can settle.
 *
 * **It is retryable.** A failure here is usually an expired token or a dataset
 * id somebody mistyped — both fixable, after which the queued event should go
 * rather than be lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_conversion_events', function (Blueprint $table) {
            $table->id();

            // Ours, generated and deterministic. See the class comment.
            $table->string('event_id', 100)->unique();
            $table->string('event_name', 60);

            // The lead or deal this is about. Nullable on delete rather than
            // cascading: the event was sent, and deleting the record does not
            // unsend it — a row that says what Meta was told is the only
            // defence against sending it again.
            $table->nullableMorphs('subject');

            $table->string('dataset_id', 64)->index();
            $table->string('action_source', 40);

            $table->decimal('value', 15, 2)->nullable();
            $table->string('currency', 3)->nullable();

            // What was sent, with the identifiers already hashed — this column
            // is a record of a request, not a second copy of the customer.
            $table->json('payload')->nullable();

            $table->string('status', 20)->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response')->nullable();
            $table->text('error')->nullable();

            // Meta's clock matters: an event is reported *for* the moment the
            // outcome happened, not the moment the queue got to it, and Meta
            // refuses anything older than seven days.
            $table->dateTime('occurred_at');
            $table->dateTime('sent_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_conversion_events');
    }
};
