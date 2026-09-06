<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Normalised fingerprints of the values duplicates are matched on.
 *
 * Matching could be done by normalising inside the query — LOWER(email),
 * digits-only phone — but every such expression is unindexable, so a duplicate
 * check on capture would scan the table. Storing the normalised value instead
 * makes the check one indexed lookup, and gives the Phase 8 ingestion gateway
 * the same "match on email or phone" engine rather than a second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicate_keys', function (Blueprint $table) {
            $table->id();
            $table->morphs('keyable');

            // The MatchStrategy that produced the value, so an email never
            // matches a phone number that happens to normalise the same.
            $table->string('kind', 20);
            $table->string('value', 191);

            // The lookup: "who else carries this fingerprint?"
            $table->index(['kind', 'value']);
            // One row per record per kind per value, so a re-sync is idempotent.
            $table->unique(['keyable_type', 'keyable_id', 'kind', 'value'], 'duplicate_keys_record_value_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_keys');
    }
};
