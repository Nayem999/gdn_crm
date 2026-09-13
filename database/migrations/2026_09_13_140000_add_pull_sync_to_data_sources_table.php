<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pull mode: us calling their system on a schedule, rather than them calling us.
 *
 * The other half of the arrangement `type` chooses between. A push source needs
 * a signed endpoint and a key; a pull source needs somewhere to fetch from,
 * credentials to fetch with, a way through their pagination, and a mark saying
 * how far it got last time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->string('pull_url', 2048)->nullable()->after('listening_until');

            /**
             * How we authenticate to them. The secret is encrypted for the same
             * reason the signing secret is: it has to be sent, so it cannot be
             * hashed, but a database backup must not carry it away.
             */
            $table->string('pull_auth_type', 16)->default('none')->after('pull_url');
            $table->string('pull_auth_name')->nullable()->after('pull_auth_type');
            $table->text('pull_auth_secret')->nullable()->after('pull_auth_name');

            // Where the records are in their response. Their envelope is their
            // business — `data`, `items`, `results` — so it is configured.
            $table->string('pull_records_path')->nullable()->after('pull_auth_secret');

            $table->string('pull_page_param', 64)->nullable()->after('pull_records_path');
            $table->string('pull_page_size_param', 64)->nullable()->after('pull_page_param');
            $table->unsignedSmallInteger('pull_page_size')->default(100)->after('pull_page_size_param');

            /**
             * The incremental cursor: which query parameter carries "everything
             * since", and where in each record the value to remember lives.
             *
             * Stored as text rather than a timestamp because it is **their**
             * value — an id, a sequence number or a date, and we do not get to
             * decide which.
             */
            $table->string('pull_cursor_param', 64)->nullable()->after('pull_page_size');
            $table->string('pull_cursor_path')->nullable()->after('pull_cursor_param');
            $table->string('pull_cursor')->nullable()->after('pull_cursor_path');

            $table->string('pull_schedule', 16)->default('manual')->after('pull_cursor');
            $table->timestamp('last_synced_at')->nullable()->after('pull_schedule');
            // What the last run did, for the screen to show without re-running
            // it. JSON because nothing filters on it.
            $table->json('last_sync_summary')->nullable()->after('last_synced_at');

            $table->index(['type', 'pull_schedule', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropIndex(['type', 'pull_schedule', 'is_active']);
            $table->dropColumn([
                'pull_url', 'pull_auth_type', 'pull_auth_name', 'pull_auth_secret',
                'pull_records_path', 'pull_page_param', 'pull_page_size_param', 'pull_page_size',
                'pull_cursor_param', 'pull_cursor_path', 'pull_cursor',
                'pull_schedule', 'last_synced_at', 'last_sync_summary',
            ]);
        });
    }
};
