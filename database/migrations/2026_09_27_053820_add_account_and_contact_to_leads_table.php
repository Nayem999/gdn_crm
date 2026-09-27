<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The account and contact a lead is already known to belong to.
 *
 * Optional, and chosen on the lead form: a lead from an existing customer can
 * be linked to their account and person before it is converted, and
 * conversion then joins them instead of creating second copies. company_name
 * stays free text — most leads are nobody on file yet.
 *
 * Distinct from converted_account_id / converted_contact_id, which record what
 * a conversion produced; these record what somebody said beforehand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // nullOnDelete: removing the account or person leaves the lead
            // unlinked rather than blocking the removal.
            $table->foreignId('account_id')->nullable()->after('company_name')->constrained('accounts')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->after('account_id')->constrained('contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_id');
            $table->dropConstrainedForeignId('account_id');
        });
    }
};
