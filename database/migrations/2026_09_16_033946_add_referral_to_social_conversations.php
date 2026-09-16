<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The advertisement a conversation started from.
 *
 * Kept on the conversation rather than only on the message that carried it,
 * because it is asked for in two places at two different times: when the first
 * message creates a lead, and weeks later when an agent presses "create lead" on
 * a thread that never made one. Meta sends the referral **once** — not on the
 * second message, and never again on request — so a thread that did not keep it
 * has lost which campaign won the customer, permanently.
 *
 * A json column rather than columns per field: Meta names the same facts
 * differently per channel and adds keys without notice, and the parts this CRM
 * counts by are copied out into `marketing_attributions` anyway, where they are
 * indexed and reportable. This is the raw record behind that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_conversations', function (Blueprint $table) {
            $table->json('referral')->nullable()->after('participant_handle');
        });
    }

    public function down(): void
    {
        Schema::table('social_conversations', function (Blueprint $table) {
            $table->dropColumn('referral');
        });
    }
};
