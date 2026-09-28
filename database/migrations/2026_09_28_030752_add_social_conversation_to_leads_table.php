<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The social inbox conversation a lead was made from.
 *
 * A chat no longer becomes a lead by itself: an agent converts it, and may do
 * so more than once (two people writing from one shop's number), so the link
 * lives on the lead. The inbox's "leads from this chat" reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // nullOnDelete: removing a conversation keeps the lead it produced.
            $table->foreignId('social_conversation_id')->nullable()->after('account_id')
                ->constrained('social_conversations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('social_conversation_id');
        });
    }
};
