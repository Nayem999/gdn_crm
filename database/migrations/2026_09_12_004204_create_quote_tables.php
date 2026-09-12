<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotes, and the counter that numbers them.
 *
 * **A quote is a document, not a view of a deal.** It carries who it was for,
 * what it said and what it came to, all snapshotted — because the customer has
 * a copy, and a quote that changed when the account was renamed or the
 * catalogue repriced would no longer be the thing they were sent.
 *
 * **Versioning is by row, not by diff.** Revising a quote writes a new row with
 * the next version number and marks the old one superseded, so both remain
 * readable exactly as they were sent. A quote history reconstructed from a
 * change log would be a reconstruction, and the one thing a disputed quote
 * needs is not to be a reconstruction.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * Gap-free document numbering.
         *
         * A table rather than `max(number) + 1`, because that query is a race:
         * two quotes created in the same second both read the same maximum and
         * both claim it. Invoices in particular are expected to be sequential
         * and gap-free, and "we skipped 1043" is a conversation with an
         * accountant rather than a bug report.
         */
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();

            // One counter per kind per period, e.g. "quote:2026".
            $table->string('key', 64)->unique();
            $table->unsignedInteger('next_value')->default(1);

            $table->timestamps();
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();

            // Human-facing and unique. Shared across versions of the same
            // quote, which is why the uniqueness is on the pair rather than the
            // number alone: Q-2026-0007 v1 and v2 are the same quote.
            $table->string('number', 32);
            $table->unsignedInteger('version')->default(1);

            /**
             * The first version of this quote. Null on version 1 itself, so
             * there is no chicken-and-egg on insert; `rootId()` reads it as
             * `root_id ?? id`.
             */
            $table->foreignId('root_id')->nullable()->constrained('quotes')->nullOnDelete();

            // Who it is for. Nullable and null-on-delete: the quote keeps its
            // own copy of the billing details, so it still reads correctly if
            // the account is later removed.
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();

            // The snapshot of who it was addressed to, as printed.
            $table->string('bill_to_name');
            $table->text('bill_to_address')->nullable();
            $table->string('bill_to_email')->nullable();

            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            $table->string('status', 16);

            // Whether the prices on this document include tax. Per document,
            // because the same company sends both — see TaxMode.
            $table->string('tax_mode', 16);

            // Which book it was priced from, kept so a later version can be
            // priced the same way. Null means the catalogue.
            $table->foreignId('price_book_id')->nullable()->constrained()->nullOnDelete();

            $table->date('issue_date');
            $table->date('valid_until')->nullable();

            $table->text('intro')->nullable();
            $table->text('terms')->nullable();
            // Internal, never printed. A quote often needs a note about why the
            // discount was given that the customer must not read.
            $table->text('notes')->nullable();

            // Written by DocumentTotals, so a report can ask "quotes over ten
            // thousand" without totalling in PHP.
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('superseded_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['number', 'version']);
            $table->index(['status', 'id']);
            $table->index(['account_id', 'id']);
            $table->index(['deal_id', 'id']);
            // The expiry sweep: sent quotes whose date has passed.
            $table->index(['status', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('document_sequences');
    }
};
