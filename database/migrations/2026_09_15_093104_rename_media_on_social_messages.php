<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `social_messages.media` becomes `attachments`, because the old name collided.
 *
 * 12.10 attaches the fetched file through Spatie's media library, which gives a
 * model a `media()` relation — and a **column** called `media` shadows it, so
 * `getFirstMedia()` read the JSON descriptors instead of the stored file and
 * failed with a type error. The same class of trap as an accessor named after
 * its column, and the fix is the same: do not let the two share a name.
 *
 * `attachments` is also the better name for what the column holds: what Meta
 * said about the file, not the file itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_messages', function (Blueprint $table) {
            $table->renameColumn('media', 'attachments');
        });
    }

    public function down(): void
    {
        Schema::table('social_messages', function (Blueprint $table) {
            $table->renameColumn('attachments', 'media');
        });
    }
};
