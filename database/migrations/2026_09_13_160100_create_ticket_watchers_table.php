<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colleagues following a ticket they are not assigned to.
 *
 * A support desk runs on this: the person who took the call, the engineer who
 * knows the product, the manager watching one angry customer. They are a
 * recipient type the notification matrix already has a row for
 * (RecipientType::Watcher) and had nothing to fill until now.
 *
 * Users only, deliberately. A customer already hears as the customer, and
 * letting an arbitrary address watch a ticket would be a way to have every
 * reply forwarded somewhere nobody audits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // One row per person per ticket. Watching twice is not a thing, and
            // without this a double-click would double every notification.
            $table->unique(['ticket_id', 'user_id']);

            // "Which tickets am I watching" is the other direction this is read.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_watchers');
    }
};
