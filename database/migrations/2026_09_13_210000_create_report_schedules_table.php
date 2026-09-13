<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A report that posts itself.
 *
 * `next_run_at` is stored rather than computed, so the sweep is one indexed
 * range scan — "which schedules are due" against a column, not a cron
 * expression evaluated per row in PHP. It is recomputed after every send, which
 * also makes a missed window self-healing: a scheduler that was down for a day
 * sends once and moves on rather than sending yesterday's twenty times.
 *
 * The report is **run as the schedule's owner**, and that is why `user_id` is
 * NOT NULL and restricts on delete. A scheduled report with no owner would have
 * no access level to run under, and the safe reading of "nobody" is "everything"
 * — which is exactly the leak. Removing the person is refused until their
 * schedules are dealt with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->string('frequency', 16);

            // 0-6, Sunday first, matching Carbon's dayOfWeek. Only read for a
            // weekly schedule.
            $table->unsignedTinyInteger('day_of_week')->nullable();

            // 1-31, clamped to the month's length at send time: a schedule set
            // to the 31st must still go out in February.
            $table->unsignedTinyInteger('day_of_month')->nullable();

            // The hour on the office clock. Minutes are not offered: nobody
            // needs a report at 09:17, and the sweep then runs hourly rather
            // than every minute.
            $table->unsignedTinyInteger('hour')->default(8);

            $table->string('format', 16)->default('pdf');

            // Plain addresses, not user ids: a report often goes to somebody
            // who has no account — an accountant, a board member.
            $table->json('recipients');

            $table->boolean('is_active')->default(true);

            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();

            // What happened last time, so a schedule that has been failing
            // silently for a fortnight is visible without reading the logs.
            $table->string('last_status', 32)->nullable();
            $table->string('last_error', 500)->nullable();

            $table->timestamps();

            // The sweep's only query.
            $table->index(['is_active', 'next_run_at']);
            $table->index('report_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
