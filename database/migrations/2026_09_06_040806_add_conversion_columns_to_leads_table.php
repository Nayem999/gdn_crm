<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a lead turned into.
 *
 * Stored on the lead rather than worked out from the other three records:
 * conversion has to be idempotent, and "has this already been converted, and
 * into what?" must be answerable in one read without guessing at a match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('converted_at')->nullable()->after('scored_at');

            // nullOnDelete throughout: removing a converted record must not take
            // the lead with it, and the lead's own status still says Converted.
            $table->foreignId('converted_account_id')->nullable()->after('converted_at')
                ->constrained('accounts')->nullOnDelete();
            $table->foreignId('converted_contact_id')->nullable()->after('converted_account_id')
                ->constrained('contacts')->nullOnDelete();
            $table->foreignId('converted_deal_id')->nullable()->after('converted_contact_id')
                ->constrained('deals')->nullOnDelete();

            $table->index('converted_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('converted_account_id');
            $table->dropConstrainedForeignId('converted_contact_id');
            $table->dropConstrainedForeignId('converted_deal_id');
            $table->dropIndex(['converted_at']);
            $table->dropColumn('converted_at');
        });
    }
};
