<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lines of a sales document — one table for quotes, orders and invoices.
 *
 * One table because a line is the same thing in all three, and because 6.4
 * converts a quote into an order and 6.5 an order into an invoice: copying
 * between three near-identical tables is three chances for them to drift, and
 * the first field somebody adds to one of them is the one that goes missing in
 * the conversion.
 *
 * **Every line is a snapshot.** The description, the unit price and the tax
 * rate are copied onto the line when it is written, not read through to the
 * product. A quote sent in March must still say what it said in March after
 * somebody puts the catalogue price up in April — a document that changes
 * retrospectively is a document nobody can rely on, and the customer has a copy
 * of the old one.
 *
 * That is also why `product_id` nulls out rather than restricting: the product
 * can be retired from the catalogue and the line still reads correctly, because
 * everything it needs is on the line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_lines', function (Blueprint $table) {
            $table->id();

            // The quote, order or invoice this belongs to. A morph because the
            // three documents are different models with the same lines.
            $table->morphs('document');

            // What was sold, when it is still in the catalogue. The line does
            // not depend on it.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('position')->default(0);

            // The snapshot. `description` is the second line under the name on
            // a printed document, and both are editable per line: quoting
            // frequently means describing the same product differently for a
            // different customer.
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit', 16);

            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);

            // A percentage or a flat amount off this line. Two columns rather
            // than one signed number, because "10% off" and "10 off" are
            // different instructions and a document has to print which was
            // meant.
            $table->string('discount_type', 16)->nullable();
            $table->decimal('discount_value', 15, 2)->default(0);

            $table->decimal('tax_rate', 5, 2)->default(0);

            /**
             * The computed figures, written when the line is saved.
             *
             * Stored rather than derived on read, for two reasons that both
             * matter: a document's totals have to be queryable — "invoices over
             * ten thousand" is a report, not a page of PHP — and a line must
             * still say what it said even if the rules that produced it change.
             * `LineCalculator` is the only writer, so there is one arithmetic.
             */
            $table->decimal('net_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);

            $table->timestamps();

            $table->index(['document_type', 'document_id', 'position']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_lines');
    }
};
