<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbound webhooks: where to tell, and what happened when we tried.
 *
 * **Every attempt is a row on the delivery, not a counter.** "It failed three
 * times" is not the question anybody asks — they ask what the endpoint said,
 * and when, and whether the last attempt is the one that matters. A counter
 * throws all of that away and leaves you re-running it to find out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('url', 2048);

            /**
             * The shared secret, encrypted at rest.
             *
             * It is what the receiver verifies the signature with, so it has to
             * be readable by the application — hashing it would make it useless
             * — but it must not sit in the database in plain text where a
             * backup carries it away.
             */
            $table->text('secret');

            // Which events this endpoint wants. An empty list means none, not
            // all: "subscribe to everything by default" is how an endpoint gets
            // a firehose nobody asked for.
            $table->json('events');

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();

            $table->string('event', 64);

            // What was sent, exactly. Kept so a replay sends the same thing
            // rather than re-deriving it from a record that has since moved on.
            $table->json('payload');

            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts')->default(0);

            // The last attempt's outcome.
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('error')->nullable();

            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('last_attempt_at')->nullable();

            $table->timestamps();

            $table->index(['webhook_endpoint_id', 'id']);
            $table->index(['status', 'id']);
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
