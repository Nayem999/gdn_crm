<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modules an administrator defines at runtime, and the records they hold.
 *
 * **No runtime DDL.** A generated module does not get a table of its own: it
 * gets rows in `custom_records`, discriminated by `custom_module_id`, and every
 * field beyond the name is a 4.1 custom field stored in `custom_field_values`.
 * The alternative — running CREATE TABLE from a web form — would mean the
 * application executing DDL on a request, which is a security and operability
 * decision nobody should make implicitly, and it puts schema changes outside
 * the migration history that every other table lives in.
 *
 * What that costs, stated plainly: a custom module's fields are joined rather
 * than columns, so they cannot be sorted in SQL and a filter on one is an
 * EXISTS subquery. That is the same deal 4.2 already struck for custom fields
 * on the built-in modules, and the indexes on `custom_field_values` are what
 * keep it honest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_modules', function (Blueprint $table) {
            $table->id();

            // Permanent and derived from the name, never taken from the
            // browser: it is what a URL, a custom field row and a saved view
            // all refer to. Same rule a custom field's key follows.
            $table->string('key', 48)->unique();
            $table->string('name');
            $table->string('plural_name');

            // What the record's title is called on screen — "Project name",
            // "Reference" — since every module's one built-in field is
            // different in the language of that module.
            $table->string('title_label')->default('Name');

            $table->string('icon', 48)->default('box');
            $table->string('color', 20)->default('slate');
            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['is_active', 'position', 'id']);
        });

        Schema::create('custom_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('custom_module_id')->constrained()->cascadeOnDelete();

            // The one column every generated module has. Everything else is a
            // custom field — so this is what the list sorts and searches on,
            // and it is a real indexed column for exactly that reason.
            $table->string('name');

            // Visibility follows the owner, like every other business model.
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['custom_module_id', 'owner_id']);
            $table->index(['custom_module_id', 'name']);
            $table->index(['custom_module_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_records');
        Schema::dropIfExists('custom_modules');
    }
};
