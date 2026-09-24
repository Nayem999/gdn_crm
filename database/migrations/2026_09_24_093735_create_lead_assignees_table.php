<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who is working a lead, replacing its single `owner_id`.
 *
 * **Several people, at once, not a queue of one.** Every assignee is equally
 * responsible for the lead the moment they are added — this is not a
 * hand-off where only the "current" person may act. `priority` is optional
 * and only orders the escalation ladder (see `leads:escalate-assignments`):
 * it never gates who may open, edit or work the lead today. A row with a
 * null priority is a plain co-assignee, present and fully able to act, but
 * outside that ladder entirely.
 *
 * `assigned_at` is when the row was created; `escalated_at` is stamped only
 * when the escalation sweep decides this tier's turn has come, and exists so
 * that sweep never notifies the same tier twice for the same wait.
 *
 * Visibility scoping (`Lead::scopeVisibleTo()`) now reads this table instead
 * of a flat column — "own" becomes "any lead I am assigned to", "team"
 * becomes "any lead assigned to somebody on my team" — so a lead with zero
 * rows here is invisible to everyone below `all` access, the same leak a
 * mandatory `owner_id` used to close. `SyncLeadAssigneesAction` is the only
 * writer and refuses to leave a lead with none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_assignees', function (Blueprint $table) {
            $table->id();

            // Global scope needed here too, not only on the parent lead: a
            // direct query against this table (rather than through
            // Lead::assignees()) must not be able to cross a workspace
            // boundary just because nobody remembered to join back to leads.
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();

            // cascadeOnUpdate as well as delete: a lead's id is never meant to
            // change, but a duplicate-collision test in this application's own
            // suite deliberately renumbers one to prove notable_id matching
            // checks the type as well as the id — and a plain cascadeOnDelete
            // still lets MySQL refuse that renumbering outright, because the
            // constraint is checked on any change to the parent key, not only
            // a delete of it.
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();

            // restrictOnDelete, matching the invariant the removed owner_id
            // enforced: a user who is currently somebody's only way of seeing
            // a lead cannot simply be deleted out from under it. Unassign
            // them first.
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            /**
             * The escalation order. Lower acts first; null means "not on the
             * ladder at all" — a co-assignee the sweep never touches.
             *
             * Nullable smallint rather than a required rank: forcing every
             * assignee into a strict 1..N order is exactly the ceremony
             * "priority value optional" was written to avoid — most leads
             * will have assignees with no order between them at all.
             */
            $table->unsignedSmallInteger('priority')->nullable();

            $table->dateTime('assigned_at');
            $table->dateTime('escalated_at')->nullable();

            $table->timestamps();

            // One row per person per lead — assigning somebody who is already
            // there changes their priority, it does not add a second row.
            $table->unique(['lead_id', 'user_id']);
            // The escalation sweep's own query: this lead's ladder, in order.
            $table->index(['lead_id', 'priority']);
            // "Everything assigned to me" — visibility scoping's own query,
            // tenant-first to match every other composite index in this
            // application.
            $table->index(['tenant_id', 'user_id']);
            // user_id is also a foreign key in its own right, and the
            // composite above does not lead with it — a plain index is what
            // keeps a delete on `users` from having to scan this table to
            // check nothing still points at the row being removed.
            $table->index('user_id');
        });

        // Every existing lead's owner becomes its one, unprioritised
        // assignee — backfilled before the column is dropped, or every lead
        // that already exists loses its only assignee in the same
        // migration that made having at least one mandatory. assigned_at
        // takes the lead's own created_at rather than now(): a backfilled
        // row should say when the assignment actually goes back to, not
        // claim it started at migration time.
        DB::table('leads')->orderBy('id')->chunkById(500, function ($leads) {
            $now = now();

            DB::table('lead_assignees')->insert($leads->map(fn ($lead) => [
                'tenant_id' => $lead->tenant_id,
                'lead_id' => $lead->id,
                'user_id' => $lead->owner_id,
                'priority' => null,
                'assigned_at' => $lead->created_at ?? $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });

        Schema::table('leads', function (Blueprint $table) {
            // The tenant-prefixed index doesn't lead with owner_id, so it
            // isn't backing the foreign key and can go first; the plain
            // owner_id index is what the constraint actually relies on, so
            // the constraint has to drop before that index can.
            $table->dropIndex('leads_tenant_owner_status_index');
            $table->dropForeign(['owner_id']);
            $table->dropIndex('leads_owner_id_status_index');
            $table->dropColumn('owner_id');
        });
    }

    public function down(): void
    {
        // Structural only, and nullable rather than restoring the original
        // NOT NULL — which user owned which lead is not recoverable once the
        // column and this table are both gone, and adding it back NOT NULL
        // onto a table that already has rows would default every one of
        // them to owner_id 0, which the foreign key then refuses outright.
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('status_changed_at')->constrained('users')->restrictOnDelete();

            $table->index(['owner_id', 'status'], 'leads_owner_id_status_index');
            $table->index(['tenant_id', 'owner_id', 'status'], 'leads_tenant_owner_status_index');
        });

        Schema::dropIfExists('lead_assignees');
    }
};
