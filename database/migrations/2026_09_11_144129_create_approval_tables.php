<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approvals: a workflow pausing to ask a person before it goes on.
 *
 * Two tables, because an approval is genuinely two things — the request, which
 * is about a record and has one outcome, and the levels, which are the people
 * asked in turn. A single table with three nullable approver columns would cap
 * the chain at three and make "who is being asked right now" a case statement.
 *
 * The **run pauses**: `workflow_runs.resume_from_position` remembers the step
 * it stopped at, so an approval genuinely gates the steps after it rather than
 * being a note recorded while the rest of the workflow ran anyway. That is the
 * whole point of an approval, and it is why this needed a column on a table 5.1
 * had already written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();

            // All three null out rather than cascade, for the same reason the
            // run log does: a decision somebody made is a fact about the past
            // and must outlive the automation that asked for it.
            $table->foreignId('workflow_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workflow_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workflow_action_id')->nullable()->constrained()->nullOnDelete();

            // Kept, so the request still reads as something afterwards.
            $table->string('workflow_name');
            $table->string('module', 48);

            // What is being approved. Nullable for the same reason a run's is:
            // a scheduled workflow has no single record.
            $table->nullableMorphs('subject');

            // What the approver is being asked to agree to, in words, written
            // when the request is made. Not derived at render time: the steps
            // it describes can be edited afterwards, and an approval must show
            // what was actually agreed to.
            $table->text('summary');

            $table->string('status', 16);

            // Which level is being asked now. Levels are sequential: one person
            // at a time, because "anyone of these five" and "all five" are
            // different features and neither is this one.
            $table->unsignedInteger('current_level')->default(0);

            // The step the run stops at, so resuming continues after the
            // approval rather than from the beginning.
            $table->unsignedInteger('resume_from_position')->default(0);

            $table->text('decision_comment')->nullable();

            // A non-nullable point in time, so dateTime() rather than
            // timestamp() — see .ai/rules/migrations.md.
            $table->dateTime('requested_at');
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // The sweep's query: open requests whose level is overdue. No
            // index on the subject here — nullableMorphs() already made one,
            // and adding it again is a duplicate key error rather than a
            // second index.
            $table->index(['status', 'id']);
        });

        Schema::create('approval_levels', function (Blueprint $table) {
            $table->id();

            // A level has no meaning without its request, so this one cascades.
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('position')->default(0);

            // Who is asked. Null-on-delete rather than restrict: removing a
            // user must not be blocked by an approval they were once asked
            // for, and a level with no approver escalates like any other
            // unanswered one.
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 16);

            // When this level stops waiting. Null means it waits indefinitely,
            // which is a real choice for an approval nobody should be able to
            // let lapse by ignoring.
            $table->timestamp('due_at')->nullable();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('comment')->nullable();

            // Set when the level ran out of time rather than being answered,
            // so the log distinguishes "nobody replied" from "somebody said
            // yes" — which matters a great deal when auditing a decision.
            $table->timestamp('escalated_at')->nullable();

            $table->timestamps();

            $table->index(['approval_request_id', 'position']);
            // The escalation sweep: waiting levels whose time has come.
            $table->index(['status', 'due_at']);
            // "What is waiting for me" on the approvals screen.
            $table->index(['approver_id', 'status']);
        });

        Schema::table('workflow_runs', function (Blueprint $table) {
            // Where to pick up when an approval says yes.
            $table->unsignedInteger('resume_from_position')->nullable()->after('status');

            // `awaiting_approval` is seventeen characters and the column was
            // sixteen. Widened rather than the value shortened: a status column
            // that only just fits its longest value is a trap for the next
            // person to add a case. Every previously-defined attribute is
            // restated, or change() would drop them — see .ai/rules/migrations.md.
            $table->string('status', 32)->nullable(false)->change();
        });

        Schema::table('workflow_run_steps', function (Blueprint $table) {
            // The same widening, for the same reason: a step's status set is a
            // subset of a run's today, and there is no reason to make the two
            // diverge in width.
            $table->string('status', 32)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_run_steps', function (Blueprint $table) {
            $table->string('status', 16)->nullable(false)->change();
        });

        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->dropColumn('resume_from_position');
            $table->string('status', 16)->nullable(false)->change();
        });

        Schema::dropIfExists('approval_levels');
        Schema::dropIfExists('approval_requests');
    }
};
