<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credentials for an inbound source.
 *
 * Its own migration rather than columns on the 8.1 table, because the two
 * answer different questions and arrived for different reasons: 8.1 is what a
 * source *is*, this is how somebody proves they are allowed to use it.
 *
 * Two credentials, stored two ways, because the maths demands it — see
 * App\Domain\Ingestion\SourceSecret. The key is hashed and compared; the
 * signing secret is encrypted, because verifying an HMAC means recomputing it
 * and a hash cannot be recomputed from.
 *
 * Each has a **previous** copy with an expiry, which is the rotation grace
 * window: the integration at the other end is somebody else's deploy, and a
 * rotation that broke it the instant it was pressed would mean nobody ever
 * rotated anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            // SHA-256 hex. Never reversible, and a backup carries nothing
            // usable away.
            $table->string('secret_hash', 64)->nullable()->after('is_sandbox');
            // The last four characters, so the screen can say which key is in
            // use without being able to show it.
            $table->string('secret_hint', 8)->nullable()->after('secret_hash');
            // Encrypted, not hashed: an HMAC key has to be readable to verify a
            // signature with it.
            $table->text('signing_secret')->nullable()->after('secret_hint');
            $table->timestamp('secret_created_at')->nullable()->after('signing_secret');

            $table->string('previous_secret_hash', 64)->nullable()->after('secret_created_at');
            $table->text('previous_signing_secret')->nullable()->after('previous_secret_hash');
            $table->timestamp('previous_secret_expires_at')->nullable()->after('previous_signing_secret');

            // Kept after a revoke, so the screen can tell "this key was taken
            // away" apart from "this source never had one" — two situations
            // that need different things said about them.
            $table->timestamp('secret_revoked_at')->nullable()->after('previous_secret_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn([
                'secret_hash',
                'secret_hint',
                'signing_secret',
                'secret_created_at',
                'previous_secret_hash',
                'previous_signing_secret',
                'previous_secret_expires_at',
                'secret_revoked_at',
            ]);
        });
    }
};
