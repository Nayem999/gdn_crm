<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What one record answered for one custom field.
 *
 * **Typed columns, not one serialised `value`.** The single-column shape is the
 * classic EAV trap: a number stored as text sorts "10" before "9", a date
 * cannot be compared with a range, and neither can carry an index worth having.
 * 4.2 puts custom fields in the filter builder, and the architecture rules ask
 * for an index on every field that appears there — so each type gets a real
 * column of its own and `CustomFieldType::column()` says which.
 *
 * The columns are nullable and mutually exclusive: exactly one is written per
 * row, chosen by the definition's type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();

            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            // The record that answered: a lead, contact, account, deal or
            // activity. Addressed by type *and* id, like every other
            // polymorphic table here.
            $table->morphs('customizable');

            // Text and single-select. Capped at 255 rather than TEXT so it can
            // carry an index — a filter on a custom field has to be indexable.
            $table->string('value_string')->nullable();
            // Number and currency. DECIMAL, never FLOAT: money is exact.
            $table->decimal('value_number', 15, 2)->nullable();
            $table->date('value_date')->nullable();
            $table->boolean('value_boolean')->nullable();
            // Multiselect only — a list of chosen option keys. Filtering a
            // multiselect is a containment test, not a comparison, so JSON is
            // the right shape rather than a compromise.
            $table->json('value_json')->nullable();
            // Lookup. Deliberately a plain unsigned integer with no foreign
            // key: which table it points at is the definition's business, and a
            // constraint cannot be conditional on another row's column.
            $table->unsignedBigInteger('value_lookup_id')->nullable();

            $table->timestamps();

            // One answer per field per record. This is what makes saving an
            // upsert rather than a delete-and-reinsert, so a value keeps its id
            // and its created_at across edits.
            $table->unique(['custom_field_id', 'customizable_type', 'customizable_id'], 'custom_field_values_unique');

            // One index per filterable column, each led by the field so a
            // filter on "Industry sector" reads only that field's rows.
            $table->index(['custom_field_id', 'value_string']);
            $table->index(['custom_field_id', 'value_number']);
            $table->index(['custom_field_id', 'value_date']);
            $table->index(['custom_field_id', 'value_boolean']);
            $table->index(['custom_field_id', 'value_lookup_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
    }
};
