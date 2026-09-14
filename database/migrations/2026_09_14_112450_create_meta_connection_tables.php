<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What this installation has connected at Meta.
 *
 * Four tables rather than one, because they have four different lifetimes and
 * four different tokens. A business is authorised once by a person; a page
 * carries a token of its own that outlives that person's session; an ad account
 * is read with the user's token; and a WhatsApp number belongs to a business
 * account that may not be the one the pages are under.
 *
 * Every token column is cast `encrypted` on its model, so what is written here
 * is ciphertext. They are deliberately not in the settings table with the app
 * credentials: those are one set for the installation, these are per connection
 * and are replaced whenever somebody reconnects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_accounts', function (Blueprint $table) {
            $table->id();

            // Meta's own ids are beyond 32 bits and arrive as strings. They are
            // never arithmetic, so they are stored as what they are.
            $table->string('business_id', 64)->unique();
            $table->string('name');

            // The token the authorising person granted. Long-lived — about
            // sixty days — and refreshed by re-authorising, which is why the
            // expiry is stored rather than assumed.
            $table->text('user_token')->nullable();
            $table->dateTime('token_expires_at')->nullable();

            // What Meta actually granted, which is not always what was asked
            // for: a person can decline individual permissions on the consent
            // screen, and the first sign of that is a capability failing weeks
            // later. Stored so the test tool can say which one is missing.
            $table->json('granted_scopes')->nullable();

            $table->string('status', 40)->default('connected');
            $table->text('last_error')->nullable();

            // Who authorised it. Restricted rather than cascaded: the person who
            // connected Meta leaving the company must not silently delete the
            // connection every lead arrives through.
            $table->foreignId('connected_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('connected_at')->nullable();
            $table->dateTime('last_synced_at')->nullable();

            $table->timestamps();

            $table->index('status');
        });

        Schema::create('meta_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_account_id')->constrained('meta_accounts')->cascadeOnDelete();

            $table->string('page_id', 64)->unique();
            $table->string('name');
            $table->string('category')->nullable();

            // A page token, not the user's. This is what reads lead forms and
            // answers Messenger, and it keeps working after the person who
            // granted it closes their laptop.
            $table->text('access_token')->nullable();

            // Whether this page's webhook subscription is in place at Meta.
            // Stored because it is a fact about Meta's side that we cannot infer
            // from anything here, and a page that silently stopped being
            // subscribed is a page whose leads stop arriving.
            $table->boolean('is_subscribed')->default(false);
            $table->dateTime('subscribed_at')->nullable();
            $table->dateTime('last_synced_at')->nullable();

            $table->timestamps();

            $table->index(['meta_account_id', 'is_subscribed']);
        });

        Schema::create('meta_ad_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_account_id')->constrained('meta_accounts')->cascadeOnDelete();

            $table->string('ad_account_id', 64)->unique();
            $table->string('name');
            $table->string('currency', 3)->nullable();
            $table->string('timezone', 64)->nullable();
            // Meta's own account status: active, disabled, unsettled. A disabled
            // account still has figures worth reading, so this is recorded
            // rather than used to hide it.
            $table->string('status', 40)->nullable();

            $table->boolean('is_active')->default(true);
            $table->dateTime('last_synced_at')->nullable();

            $table->timestamps();

            $table->index(['meta_account_id', 'is_active']);
        });

        Schema::create('whatsapp_business_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_account_id')->constrained('meta_accounts')->cascadeOnDelete();

            $table->string('waba_id', 64)->unique();
            $table->string('name');
            $table->string('timezone', 64)->nullable();
            $table->string('message_template_namespace', 128)->nullable();

            // The token WhatsApp Cloud API calls are made with. Separate from
            // the page token: a WhatsApp business account is not a page, and the
            // two are frequently owned by different businesses.
            $table->text('access_token')->nullable();

            $table->boolean('is_subscribed')->default(false);
            $table->dateTime('last_synced_at')->nullable();

            $table->timestamps();
        });

        Schema::create('whatsapp_phone_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_business_account_id')->constrained('whatsapp_business_accounts')->cascadeOnDelete();

            $table->string('phone_number_id', 64)->unique();
            $table->string('display_number', 32);
            $table->string('verified_name')->nullable();
            // Meta's quality rating and messaging limit, both of which decide
            // what this number is allowed to send today.
            $table->string('quality_rating', 20)->nullable();
            $table->string('messaging_limit', 40)->nullable();

            // The number this installation sends from. One at a time: a CRM that
            // sent from whichever number came first would produce conversations
            // customers cannot reply to.
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            // Named, because the generated name — table, then both columns,
            // then `_index` — is 68 characters and MySQL stops at 64.
            $table->index(['whatsapp_business_account_id', 'is_default'], 'wa_numbers_account_default_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_phone_numbers');
        Schema::dropIfExists('whatsapp_business_accounts');
        Schema::dropIfExists('meta_ad_accounts');
        Schema::dropIfExists('meta_pages');
        Schema::dropIfExists('meta_accounts');
    }
};
