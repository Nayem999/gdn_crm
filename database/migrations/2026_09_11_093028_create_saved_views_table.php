<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A named arrangement of one list screen: its filters, columns, sort and view
 * mode, saved so somebody can come back to it.
 *
 * Distinct from `user_view_preferences`, which is the *last* layout each person
 * left a module in and has exactly one row per person per module. A saved view
 * is deliberate and named, there can be many, and it can be shared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();

            $table->string('module', 32);
            $table->string('name');

            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            // Shared views are visible to anyone who may see the module. A
            // private one is the owner's alone — which is why the visibility
            // query is (owner_id = me OR is_shared), and why it is a column
            // rather than a share table: "everyone on this installation" is
            // the only audience a single-organization CRM has.
            $table->boolean('is_shared')->default(false);

            /**
             * The whole arrangement, read back as one value and never filtered
             * on — which is exactly what the architecture rules allow JSON for.
             * Re-checked against the screen's own registries on apply, so a
             * column or filter field that has since gone cannot come back.
             */
            $table->json('state');

            $table->timestamps();

            // The list screen reads one module's views, own and shared.
            $table->index(['module', 'owner_id']);
            $table->index(['module', 'is_shared']);
            // Two views with the same name on the same module, owned by the
            // same person, are a mistake rather than a feature.
            $table->unique(['module', 'owner_id', 'name']);
        });

        Schema::table('user_view_preferences', function (Blueprint $table) {
            // Which saved view this person opens the module on. Per user, not
            // per view: an owner sharing a view should not be able to change
            // what everybody else's module opens on.
            $table->foreignId('default_saved_view_id')
                ->nullable()
                ->after('module')
                ->constrained('saved_views')
                // Null on delete, not cascade: losing a default view must not
                // take the person's column layout with it.
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_view_preferences', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_saved_view_id');
        });

        Schema::dropIfExists('saved_views');
    }
};
