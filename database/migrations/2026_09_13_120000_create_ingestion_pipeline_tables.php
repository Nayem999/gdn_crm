<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the processing pipeline reads: which deliveries to act on, where each
 * value comes from, and how to tell a new record from one already here.
 *
 * Tables rather than JSON on the source, because 8.5 reorders mappings and 8.6
 * attaches a transform to each one — both of those want a row with an identity,
 * not an array position that shifts when somebody inserts a line above it.
 *
 * Dedupe lives on the source itself: "match on email, then update" is one
 * decision about the source as a whole, read whole, never filtered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_source_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();

            // A dotted path into the payload. Never a column name — this reads
            // from somebody else's JSON, and the mapping is what turns it into
            // a field of ours.
            $table->string('path');
            $table->string('operator', 24)->default('equals');
            $table->string('value', 500)->nullable();

            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['data_source_id', 'position']);
        });

        Schema::create('data_source_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();

            $table->string('source_path');

            /**
             * A field on the target module, checked against that module's own
             * declaration before it is ever written. A payload cannot reach
             * this column, and this column cannot name a column that does not
             * exist — see IngestionFields.
             */
            $table->string('target_field');
            $table->boolean('is_custom_field')->default(false);

            // 8.6 fills these in. Declared here because the pipeline reads them
            // and a mapping without a transform is simply one with none.
            $table->string('transform', 32)->nullable();
            $table->json('transform_options')->nullable();

            // Used when the path is absent or empty in the payload.
            $table->string('default_value', 500)->nullable();

            /**
             * A delivery missing a required value fails rather than creating a
             * half record. The alternative — quietly writing null — produces
             * rows nobody can tell from deliberate blanks.
             */
            $table->boolean('is_required')->default(false);

            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['data_source_id', 'position']);
        });

        Schema::table('data_sources', function (Blueprint $table) {
            /**
             * Where the sending system's own id lives in the payload.
             *
             * The strongest idempotency key there is: the sender telling us
             * which of *their* records this is. Falling back to a body hash
             * catches exact redeliveries and nothing else, because an updated
             * record has a different body.
             */
            $table->string('external_id_path')->nullable()->after('target_module');

            // Target fields to match an existing record on, when there is no
            // external id. Read whole, for one source, never filtered.
            $table->json('dedupe_fields')->nullable()->after('external_id_path');

            // What to do when a match is found: update, skip, or create anyway.
            $table->string('dedupe_action', 16)->default('update')->after('dedupe_fields');

            // Who owns what this source creates. Null means whoever set the
            // source up — a record with no owner is how visibility springs a
            // leak.
            $table->foreignId('default_owner_id')->nullable()->after('dedupe_action')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_owner_id');
            $table->dropColumn(['external_id_path', 'dedupe_fields', 'dedupe_action']);
        });

        Schema::dropIfExists('data_source_mappings');
        Schema::dropIfExists('data_source_filters');
    }
};
