<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable message bodies, and the thread that ties a sent message back to the
 * open and click reported against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            /**
             * Which module's merge fields this template may use.
             *
             * A template written for a quote offers {{quote.total}}; one written
             * for a lead does not have a quote to put in it. Declaring the
             * module is what lets the editor list the right fields and warn
             * about a field that will never resolve.
             */
            $table->string('module', 32);

            $table->string('subject');
            $table->longText('body');

            $table->boolean('is_active')->default(true);

            /**
             * Whether to add the tracking pixel and rewrite the links.
             *
             * Per template, not global: a password reset should not be tracked,
             * and a proposal probably should. Off is the safer default for a
             * new template.
             */
            $table->boolean('track_opens')->default(false);
            $table->boolean('track_clicks')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['module', 'is_active']);
        });

        Schema::table('email_messages', function (Blueprint $table) {
            /**
             * Our own id for the message, put in the body's tracking links
             * before it is sent and reported back on the way out in a header.
             *
             * It exists because the provider's id does not: the body has to
             * carry a pixel URL before anything has been handed to a provider,
             * so the id in that URL cannot be one the provider assigns.
             */
            $table->uuid('tracking_id')->nullable()->after('message_id');

            $table->index('tracking_id');
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropIndex(['tracking_id']);
            $table->dropColumn('tracking_id');
        });

        Schema::dropIfExists('email_templates');
    }
};
