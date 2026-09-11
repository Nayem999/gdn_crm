<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a field sits on the form, and when it is shown at all.
 *
 * Layout is `section` plus the `position` the table already has: a section
 * groups fields under a heading, and position orders them within it. That is
 * the whole of a layout — there is deliberately no grid of rows and columns,
 * because a form that can be arranged into an arbitrary grid is one that can be
 * arranged into an unreadable one, and every screen here is responsive.
 *
 * `visible_when` is one condition, not a tree. "Show the lost reason when the
 * status is lost" is the shape people actually need; nested AND/OR on a form
 * field is Phase 5's workflow builder, and duplicating a cut-down version of it
 * here would give two condition languages that disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            // Null means the default section, so every field defined before
            // this migration keeps appearing exactly where it did.
            $table->string('section', 64)->nullable()->after('help');
            // Whether the field takes the full width of the form rather than
            // sharing a row.
            $table->boolean('is_full_width')->default(false)->after('section');
            // {field, operator, value}. JSON is right here: nothing filters or
            // joins on a condition — it is read whole, for one field, at render
            // and validation time.
            $table->json('visible_when')->nullable()->after('default_value');
        });
    }

    public function down(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropColumn(['section', 'is_full_width', 'visible_when']);
        });
    }
};
