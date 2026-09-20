<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere to keep the ad account's own token.
 *
 * The connection screen has asked for one since per-asset tokens were added,
 * and then threw it away: it was used to read the ad account at connect time
 * and never stored, because this table had no column for it. Every later call
 * — the capability test, the nightly structure sync, the insights sync — fell
 * back to the connection's token.
 *
 * That is invisible while one system user holds everything, and it is the
 * whole problem when it does not. A business given a separate permanent token
 * for Marketing API pastes it, sees "Ad account is connected", and then watches
 * the ad account fail every check afterwards against a credential it was never
 * meant to use.
 *
 * The audit columns come too, so `meta:check-tokens` can report on this one
 * the way it reports on the others.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_ad_accounts', function (Blueprint $table) {
            // text, not string: encrypted at the model, and Meta's system user
            // tokens are already over 200 characters before encryption.
            $table->text('access_token')->nullable();

            $table->string('token_type', 24)->nullable();
            $table->string('token_app_id', 32)->nullable();
            $table->string('token_error', 255)->nullable();
            $table->dateTime('token_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('meta_ad_accounts', function (Blueprint $table) {
            $table->dropColumn(['access_token', 'token_type', 'token_app_id', 'token_error', 'token_checked_at']);
        });
    }
};
