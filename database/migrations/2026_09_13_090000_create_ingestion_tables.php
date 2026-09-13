<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The inbound data gateway: who may send us records, and what they sent.
 *
 * Two tables with very different jobs. `data_sources` is configuration an
 * administrator edits; `integration_events` is an append-only record of
 * deliveries that is never edited by hand and is the only account of what
 * actually arrived.
 *
 * The event row carries a **copy** of the decisions that applied at the time —
 * whether the source was in sandbox mode, whether the signature verified, which
 * address it came from. A source is configuration and configuration changes; a
 * log that re-derived its own meaning from today's settings would answer the
 * question "what happened last Tuesday" with today's answer.
 *
 * Authentication columns are deliberately absent: task 8.2 owns secret
 * generation, hashing and rotation, and 8.8 owns the pull-mode endpoint and
 * cursor. Adding their columns here would mean a table of fields nothing reads
 * and a migration nobody could explain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();

            /**
             * The public identifier, used in the ingest path (8.3).
             *
             * A uuid rather than the primary key: the URL is handed to an
             * outside system, and a sequential id in it tells that system how
             * many integrations exist and lets it guess the next one.
             */
            $table->uuid('uuid')->unique();

            $table->string('name');
            $table->string('description', 500)->nullable();

            // push or pull. Which half of the configuration is meaningful
            // depends on it, so it is chosen up front, not inferred.
            $table->string('type', 16)->default('push');

            // A module key from IngestionTargets, never a class name. This is
            // the whole of "a source can only write into its target module":
            // the pipeline resolves this stored value, and nothing in a payload
            // can reach it.
            $table->string('target_module', 32);

            $table->boolean('is_active')->default(true);

            /**
             * Sandbox: capture and map, but write nothing.
             *
             * Its own flag rather than "inactive": an integration being built
             * needs to send real payloads and see what they would produce, and
             * turning the source off would stop it being sent anything to look
             * at.
             */
            $table->boolean('is_sandbox')->default(false);

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // "Which sources are live" is the question the gateway asks on
            // every delivery, and the health dashboard (8.9) asks per type.
            $table->index(['is_active', 'type']);
            $table->index('target_module');
        });

        Schema::create('integration_events', function (Blueprint $table) {
            $table->id();

            /**
             * A public id for one delivery, so a support conversation can name
             * it without exposing how many have been received.
             */
            $table->uuid('uuid')->unique();

            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();

            $table->string('status', 16)->default('received');

            /**
             * The body exactly as it arrived.
             *
             * longText, not json: the signature is computed over the bytes that
             * were sent, so a column that reserialises them makes the signature
             * unverifiable afterwards and a replay send something subtly
             * different from the original. It is stored before anything parses
             * it, and it is data — never evaluated, unserialised or executed.
             */
            $table->longText('payload')->nullable();

            /**
             * The headers kept for forensics, and what the mapping produced.
             * Both are ours, so json is right for them.
             */
            $table->json('headers')->nullable();
            $table->json('mapped_output')->nullable();

            /**
             * The sending system's own id for the thing, once the mapping has
             * found it. What makes a redelivery update one record rather than
             * create a second (8.4).
             */
            $table->string('external_id')->nullable();

            // What the delivery produced, when it produced something.
            $table->nullableMorphs('record');
            $table->string('outcome', 16)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->boolean('signature_verified')->default(false);

            /**
             * Whether the *source* was in sandbox mode for this delivery.
             *
             * Copied, not joined. Somebody takes a source out of sandbox the
             * moment it works, and every event before that would then read as
             * though it had written a record.
             */
            $table->boolean('is_sandbox')->default(false);

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();

            // dateTime, not timestamp: MySQL and MariaDB give the first NOT
            // NULL TIMESTAMP column an implicit ON UPDATE CURRENT_TIMESTAMP, so
            // processing a delivery would silently rewrite when it arrived.
            // See .ai/rules/migrations.md.
            $table->dateTime('received_at');
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            // The log viewer reads one source newest first; the health
            // dashboard counts failures per source over a window.
            $table->index(['data_source_id', 'id']);
            $table->index(['data_source_id', 'status', 'received_at']);
            $table->index(['status', 'received_at']);
            // Dedupe looks a delivery up by the sender's own id, per source.
            $table->index(['data_source_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
        Schema::dropIfExists('data_sources');
    }
};
