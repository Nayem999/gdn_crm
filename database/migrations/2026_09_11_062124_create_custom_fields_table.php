<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fields an administrator has added to a module.
 *
 * Definitions only — the values live in `custom_field_values`. Custom fields are
 * data, not columns: a CRM that added a column per customer question would need
 * a migration per customer, and the architecture rules forbid it.
 *
 * `key` rather than the id is what everything else refers to — an import
 * mapping, a saved filter, an export column — so a field can be renamed without
 * rewriting any of them. It is unique within a module and never changes once
 * set, exactly as a pipeline stage's key is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();

            // Which module the field belongs to, as a registry key rather than
            // a class name — nothing from a request ever names a class. See
            // CustomFieldRegistry.
            $table->string('module', 32);
            $table->string('key', 64);
            $table->string('label');
            $table->string('type', 32);

            $table->text('help')->nullable();
            $table->boolean('is_required')->default(false);
            // Hidden rather than removed: switching a field off keeps the
            // values it has already collected, which deleting it would not.
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);

            // Choices for select and multiselect. JSON is allowed here because
            // nothing filters or joins on the *list* — filtering happens on a
            // value in custom_field_values, which has a real column for it.
            $table->json('options')->nullable();

            // Which module a lookup field points at. Null for every other type.
            $table->string('lookup_module', 32)->nullable();

            $table->string('default_value')->nullable();

            $table->timestamps();

            // A key means one field within its module, permanently.
            $table->unique(['module', 'key']);
            // The form and the column manager both read one module's fields in
            // order, and only the active ones.
            $table->index(['module', 'is_active', 'position', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_fields');
    }
};
