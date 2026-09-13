<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listen mode: a sample of what this source actually sends.
 *
 * Building a mapping against documentation is building it against what the
 * other system's documentation says it sends, which is rarely what it sends.
 * One real payload, captured on the way past, turns the mapping screen from a
 * form you type paths into to a list you click.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            // The raw body, kept as it arrived so the paths offered are the
            // real ones. longText for the same reason the event's payload is.
            $table->longText('sample_payload')->nullable()->after('dedupe_action');
            $table->timestamp('sample_captured_at')->nullable()->after('sample_payload');

            /**
             * Listening expires rather than being switched off.
             *
             * A mode somebody turns on and forgets is a mode that quietly
             * overwrites the sample months later, halfway through a
             * conversation about why the mapping stopped matching. It also
             * clears itself the moment it catches something.
             */
            $table->timestamp('listening_until')->nullable()->after('sample_captured_at');
        });
    }

    public function down(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn(['sample_payload', 'sample_captured_at', 'listening_until']);
        });
    }
};
