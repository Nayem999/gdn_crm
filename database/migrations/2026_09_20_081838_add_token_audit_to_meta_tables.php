<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Meta says about each token we hold.
 *
 * A stored token is a claim, not a fact: it was accepted on the day it was
 * pasted, and nothing since then has asked. Tokens are revoked — a system user
 * removed from an app, an asset unassigned, somebody regenerating a token in
 * Business Settings — and the application found out the way it found out here,
 * by failing to send a message and relaying Meta's own wording, which names an
 * app id and reads like a misconfiguration when the truth is that a credential
 * died.
 *
 * These columns hold the answer to `debug_token` for each stored token, so the
 * connection screen can say which app issued it, what kind of credential it is
 * and whether Meta still accepts it — before somebody tries to use it.
 */
return new class extends Migration
{
    /**
     * Every table that holds a token of its own.
     *
     * The ad account is absent: it has no token, it borrows the account's.
     *
     * @var array<int, string>
     */
    private const TABLES = ['meta_accounts', 'meta_pages', 'whatsapp_business_accounts'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                // USER, PAGE or SYSTEM_USER, in Meta's own vocabulary. It is
                // how a page token pasted into the WhatsApp field is spotted:
                // the label on the box says one thing and Meta says another.
                $blueprint->string('token_type', 24)->nullable();

                // Which app issued it. Two tokens from two apps cannot share
                // one appsecret_proof, and this is what makes that visible
                // rather than a signature failure nobody can read.
                $blueprint->string('token_app_id', 32)->nullable();

                // Null means "Meta accepted it when last asked". The text is
                // Meta's reason, kept so the screen can be specific about what
                // to do rather than saying "reconnect" at everything.
                $blueprint->string('token_error', 255)->nullable();

                $blueprint->dateTime('token_checked_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn(['token_type', 'token_app_id', 'token_error', 'token_checked_at']);
            });
        }
    }
};
