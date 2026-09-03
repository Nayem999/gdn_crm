<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The admin matrix: one row per event x recipient type x channel that
        // has been changed away from the registry's default.
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->string('event');
            $table->string('recipient_type');
            $table->string('channel');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['event', 'recipient_type', 'channel'], 'notification_settings_unique');
            $table->index(['event', 'recipient_type']);
        });

        // One editable template per event per channel.
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event');
            $table->string('channel');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->unique(['event', 'channel']);
        });

        // Per-user overrides. A null event mutes the channel everywhere; a named
        // event mutes it for that event only, and beats the null row.
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event')->nullable();
            $table->string('channel');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'event', 'channel'], 'notification_preferences_unique');
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event')->index();
            $table->string('channel');
            $table->string('recipient_type');
            // Nullable: a recipient can be an address with no user behind it.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient')->nullable();
            $table->string('status')->default('queued');
            $table->string('subject')->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            // The viewer filters by status and channel, newest first.
            $table->index(['status', 'created_at']);
            $table->index(['channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('notification_settings');
    }
};
