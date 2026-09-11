<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workflow engine's schema: what fires, what it checks, what it does, and
 * what happened.
 *
 * Four tables rather than one JSON document, and each split is load-bearing:
 *
 * - **Actions are rows, not an array on the workflow.** 5.4 logs a result per
 *   action and 5.8 retries an individual step, and both need something stable
 *   to point at. An index into a JSON array is not that — it moves when
 *   somebody reorders the list.
 * - **The log survives the definition.** A run keeps the workflow's name and
 *   each step keeps its action's type, so deleting a workflow leaves a readable
 *   history instead of a page of blanks. The foreign keys null out; they do not
 *   cascade.
 *
 * The condition tree is the one thing stored as JSON, because it is the filter
 * builder's own array shape — the same structure `saved_views` keeps and
 * `FilterGroup::fromArray()` reads. A workflow condition and a filter chip mean
 * the same thing because they are the same code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->text('description')->nullable();

            // Which module's records this watches. A key, matched against
            // WorkflowModules and nowhere else — never a class name from a
            // form.
            $table->string('module', 48);

            /**
             * What fires it.
             *
             * `trigger_event`, not `trigger`: TRIGGER is a reserved word in
             * MySQL. Eloquent quotes identifiers so a column called `trigger`
             * would work, right up until the first piece of hand-written SQL or
             * a tool that does not quote.
             */
            $table->string('trigger_event', 32);

            // Which field a "when a field changes" trigger watches, and which
            // date column a date trigger counts from. Null for the triggers
            // that need neither.
            $table->string('trigger_field', 64)->nullable();

            // Minutes before (negative) or after (positive) the date field.
            $table->integer('date_offset_minutes')->nullable();

            /**
             * The condition tree, in the filter builder's array shape.
             *
             * An empty group means "no conditions" and matches every record,
             * which is why it is NOT NULL with a default written by the model:
             * a null tree and an empty one would be two ways to say the same
             * thing, and something would eventually check only one of them.
             */
            $table->json('conditions');

            $table->boolean('is_active')->default(false);

            // Workflows on the same module run in this order. Two that both
            // set a field would otherwise race, and the winner would be
            // whatever the database felt like returning first.
            $table->unsignedInteger('position')->default(0);

            /**
             * Whether a record may be processed by this workflow more than
             * once. The guard against a workflow that updates a field, which
             * fires the update trigger, which runs the workflow again.
             */
            $table->boolean('run_once_per_record')->default(false);

            // Who last saved it. Nullable and null-on-delete: a workflow
            // outlives the person who wrote it.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // What somebody checks first when asking "is this thing working".
            $table->unsignedInteger('run_count')->default(0);
            $table->timestamp('last_run_at')->nullable();

            $table->timestamps();

            // The engine's own lookup: "what should fire for a lead that was
            // just updated".
            $table->index(['module', 'trigger_event', 'is_active']);
            $table->index(['is_active', 'position', 'id']);
        });

        Schema::create('workflow_actions', function (Blueprint $table) {
            $table->id();

            // An action has no meaning without its workflow, so this one does
            // cascade — unlike the log, which is a record of the past.
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();

            $table->string('type', 32);

            /**
             * Everything the action needs, shaped by its type: which field to
             * set, which template to send, which URL to call.
             *
             * JSON because each type's settings are different and nothing
             * filters across them — an action's config is read whole, for one
             * action, when it runs.
             */
            $table->json('config');

            $table->unsignedInteger('position')->default(0);

            // Switching one step off is how somebody narrows down a misbehaving
            // workflow without dismantling it.
            $table->boolean('is_active')->default(true);

            // Whether the rest of the workflow continues when this step fails.
            // Sending a courtesy email should not stop the follow-up task being
            // created; writing a field probably should.
            $table->boolean('stop_on_failure')->default(true);

            $table->timestamps();

            $table->index(['workflow_id', 'position']);
        });

        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();

            // Null-on-delete, not cascade: the point of a log is that it
            // outlives what it describes.
            $table->foreignId('workflow_id')->nullable()->constrained()->nullOnDelete();

            // Kept so a deleted workflow's history still reads as something
            // rather than as a row of blanks.
            $table->string('workflow_name');

            $table->string('module', 48);
            $table->string('trigger_event', 32);

            /**
             * The record this ran for.
             *
             * Nullable because a scheduled workflow fires without one — it is
             * the schedule that triggered it, not a record.
             */
            $table->nullableMorphs('subject');

            $table->string('status', 16);

            /**
             * The guard that makes "fires exactly once" enforceable rather than
             * hoped for.
             *
             * A unique index, because the alternative is a check-then-insert
             * that two queue workers can both pass. The engine composes the key
             * from the workflow, the subject and whatever makes the occasion
             * unique; null means "no claim", so ad-hoc and replayed runs are
             * not constrained by it.
             */
            $table->string('dedupe_key', 191)->nullable()->unique();

            /**
             * Why a run was skipped, or how it failed. Text, and never a
             * serialised exception: a log is read by people.
             */
            $table->text('message')->nullable();

            /**
             * What the trigger saw — the changed fields and their old values,
             * for a run that fired on a change.
             */
            $table->json('context')->nullable();

            // A non-nullable point in time, so dateTime() rather than
            // timestamp(): see .ai/rules/migrations.md. A timestamp column here
            // would be silently rewritten by the UPDATE that finishes the run.
            $table->dateTime('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();

            $table->index(['workflow_id', 'id']);
            $table->index(['status', 'id']);
        });

        Schema::create('workflow_run_steps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workflow_run_id')->constrained()->cascadeOnDelete();

            // The action that ran, if it still exists. Same reasoning as the
            // run's workflow: the step keeps its own copy of what it was.
            $table->foreignId('workflow_action_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action_type', 32);
            $table->unsignedInteger('position')->default(0);

            $table->string('status', 16);
            $table->text('message')->nullable();

            /**
             * What the step actually did — the field it set and to what, the
             * id of the record it created. This is what makes a log worth
             * reading rather than a list of green ticks.
             */
            $table->json('result')->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();

            $table->index(['workflow_run_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_run_steps');
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('workflow_actions');
        Schema::dropIfExists('workflows');
    }
};
