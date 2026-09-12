<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A number to reach a colleague on.
 *
 * Added with the SMS and WhatsApp drivers rather than before them, because
 * until 7.6 there was nothing that could use it. Notifications to a *customer*
 * already worked — a contact's number is carried on the message as an explicit
 * address — but a notification to the assigned rep or the team admin had no
 * number to send to at all, which would have made the two new channels
 * internal-facing in name only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
