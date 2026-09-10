<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks, calls and meetings — the work people do between records.
 *
 * One table for all three, because they differ only in a handful of optional
 * fields and every screen wants them merged: "what is due today" is not a
 * question about tasks alone. `type` says which.
 *
 * Not to be confused with `activity_log`, which is the audit trail. The two
 * words collide throughout this codebase; .ai/rules/activities.md says how
 * they are kept apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32)->default('task');
            $table->string('subject');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('open');
            // Ranked rather than named, so ORDER BY means "most urgent first".
            // See the note on the ActivityPriority enum.
            $table->unsignedTinyInteger('priority')->default(2);

            // dateTime, not timestamp: MySQL and MariaDB hand the first NOT NULL
            // TIMESTAMP column an implicit ON UPDATE CURRENT_TIMESTAMP, which
            // would move a due date every time the row was touched. See
            // .ai/rules/migrations.md.
            $table->dateTime('due_at');
            // An all-day task shows a date; a call or meeting shows a time.
            $table->boolean('all_day')->default(false);
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('location')->nullable();

            // Nullable timestamps are exempt from the trap above: they are given
            // DEFAULT NULL and no ON UPDATE clause.
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_notes')->nullable();

            $table->unsignedInteger('reminder_minutes_before')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();

            // Recurrence as columns rather than a JSON rule or an RRULE string:
            // "every open weekly task" has to be answerable as a query, and the
            // architecture rules keep JSON for what nothing filters on.
            $table->string('recurrence_frequency', 16)->nullable();
            $table->unsignedSmallInteger('recurrence_interval')->default(1);
            $table->date('recurrence_until')->nullable();
            $table->unsignedSmallInteger('recurrence_count')->nullable();
            // An occurrence points at the series it belongs to. Cascade so a
            // force-deleted series leaves no orphans behind.
            $table->foreignId('recurrence_parent_id')->nullable()
                ->constrained('activities')->cascadeOnDelete();

            // What it is about: a lead, contact, account or deal, or nothing.
            $table->nullableMorphs('related');

            // Who is expected to do it. Visibility follows this column.
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // "My open work, soonest first" is the query this table exists for.
            $table->index(['owner_id', 'status', 'due_at']);
            // The board groups by status; the calendar reads a date window.
            $table->index(['status', 'due_at']);
            $table->index(['type', 'status']);
            // Every field the filter builder offers is indexed.
            $table->index('priority');
            $table->index('completed_at');
            // The reminder sweep: open, unsent, due within the lead time.
            $table->index(['status', 'reminder_sent_at', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
