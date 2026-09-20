<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leads belong to a workspace.
 *
 * The first module to move, and the shape every other one will follow:
 *
 * - the column, NOT NULL after the backfill, because a lead belonging to
 *   nobody is a lead nobody can see and every query has to remember to exclude
 * - restrictOnDelete rather than cascade: deleting a workspace should not be
 *   something a foreign key does quietly in the background. Removing a
 *   customer's data is a deliberate act with an order to it, and a cascade
 *   from here would take the leads while leaving their activities, documents
 *   and attributions pointing at nothing
 * - the column **first** in every index it joins, because every query starts
 *   with it and an index that does not lead with it cannot be used for the
 *   narrowing that matters most
 *
 * The existing leads go to the workspace the installation already belonged to,
 * which the tenants migration created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->restrictOnDelete();
        });

        DB::table('leads')->update(['tenant_id' => DB::table('tenants')->min('id')]);

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable(false)->change();

            // The list's own query: one workspace, newest first, usually
            // filtered by status or owner. Leading with the tenant is what
            // makes these usable at all once there are many workspaces.
            $table->index(['tenant_id', 'status', 'created_at'], 'leads_tenant_status_created_index');
            $table->index(['tenant_id', 'owner_id', 'status'], 'leads_tenant_owner_status_index');
            // Duplicate detection and "have we seen this address" searches,
            // which are per workspace: two customers sharing a prospect is
            // normal and not a duplicate.
            $table->index(['tenant_id', 'email'], 'leads_tenant_email_index');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_tenant_status_created_index');
            $table->dropIndex('leads_tenant_owner_status_index');
            $table->dropIndex('leads_tenant_email_index');
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
