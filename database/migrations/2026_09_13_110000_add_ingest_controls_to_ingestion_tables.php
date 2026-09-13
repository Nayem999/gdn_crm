<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the ingest endpoint needs to refuse a request, and to prove it was not a
 * replay.
 *
 * On the source: which addresses may send, and which of the two authentication
 * methods this particular integration uses. On the event: the fingerprints a
 * replay check compares against — capture is 8.3's job, and these are the
 * columns capture produces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            /**
             * Addresses allowed to post here. Null or empty means "anywhere",
             * which is the honest default: most integrations run somewhere with
             * no fixed address, and a list nobody can fill in correctly is a
             * list people switch off.
             *
             * JSON because nothing filters on it — it is read whole, for one
             * source, on a request that has already found that source.
             */
            $table->json('ip_allowlist')->nullable()->after('is_sandbox');

            /**
             * The brief offers a bearer key **and/or** an HMAC signature, so a
             * source says which it uses. Both default to true: the safe
             * arrangement is the one somebody gets without choosing.
             *
             * Turning both off is refused everywhere it can be set — a source
             * that authenticates nothing is an open door with a uuid on it.
             */
            $table->boolean('requires_key')->default(true)->after('ip_allowlist');
            $table->boolean('requires_signature')->default(true)->after('requires_key');
        });

        Schema::table('integration_events', function (Blueprint $table) {
            // SHA-256 of the raw body. The brief's idempotency key is
            // "source + external_id, or body hash", and this is that fallback.
            $table->string('body_hash', 64)->nullable()->after('payload');

            /**
             * SHA-256 of the signature header.
             *
             * A replay presents the *identical* signed request, so this matches
             * exactly and only then. Fingerprinting the body alone would refuse
             * a second, legitimate delivery that happened to carry the same
             * payload — two identical "task updated" events a minute apart are
             * not an attack.
             */
            $table->string('signature_fingerprint', 64)->nullable()->after('body_hash');

            $table->index(['data_source_id', 'signature_fingerprint']);
            $table->index(['data_source_id', 'body_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('integration_events', function (Blueprint $table) {
            $table->dropIndex(['data_source_id', 'signature_fingerprint']);
            $table->dropIndex(['data_source_id', 'body_hash']);
            $table->dropColumn(['body_hash', 'signature_fingerprint']);
        });

        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn(['ip_allowlist', 'requires_key', 'requires_signature']);
        });
    }
};
