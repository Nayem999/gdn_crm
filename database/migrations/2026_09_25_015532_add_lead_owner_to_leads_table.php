<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional, informational owner for a lead.
 *
 * Not `owner_id`, deliberately: across this application `owner_id` is the
 * column visibility scoping, workflow assignment and load counting read, and a
 * lead's visibility comes from its assignees instead. A differently named
 * column cannot be picked up by any of that generic code by accident. Who owns
 * a lead is a label — it never decides who can see or work it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // nullOnDelete: an optional label, so removing a user simply
            // leaves their leads without an owner rather than blocking it.
            $table->foreignId('lead_owner_id')->nullable()->after('status_changed_at')->constrained('users')->nullOnDelete();

            $table->index(['tenant_id', 'lead_owner_id']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'lead_owner_id']);
            $table->dropConstrainedForeignId('lead_owner_id');
        });
    }
};
