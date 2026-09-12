<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices and the payments against them.
 *
 * **Two states, not one.** `status` is what the document is — a draft, issued,
 * or cancelled — and that is a decision somebody made. Whether it is paid is
 * not a decision: it follows from the payments recorded against it, and storing
 * it as a status invites the two to disagree, which on an invoice means either
 * chasing a customer who has paid or not chasing one who has not.
 *
 * So the paid state is **derived** from `amount_paid`, and `amount_paid` is a
 * sum maintained by the one action that records payments. That is
 * denormalisation, and it earns its place: "which invoices are outstanding" is
 * a query, not a page of PHP, and payments are rare enough that maintaining it
 * costs nothing. A test asserts it equals the sum of its payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            $table->string('number', 32)->unique();

            // Where it came from. Both null out: an invoice is the document
            // that matters afterwards and must survive either.
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();

            $table->string('bill_to_name');
            $table->text('bill_to_address')->nullable();
            $table->string('bill_to_email')->nullable();

            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            // What the document is. Whether it is paid is not here — see above.
            $table->string('status', 16);
            $table->string('tax_mode', 16);

            $table->date('issue_date');
            $table->date('due_date');

            $table->text('terms')->nullable();
            $table->text('notes')->nullable();

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);

            /**
             * The sum of the payments, maintained by RecordPaymentAction.
             *
             * Stored so "what is outstanding" can be asked in SQL. Nothing else
             * writes it, and a test checks it against the payments.
             */
            $table->decimal('amount_paid', 15, 2)->default(0);

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'id']);
            $table->index(['account_id', 'id']);
            // The chasing query: issued invoices past their date with something
            // still owed.
            $table->index(['status', 'due_date']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            // A payment has no meaning without its invoice.
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();

            $table->decimal('amount', 15, 2);

            // The day the money arrived, which is frequently not the day
            // somebody got round to recording it — and it is the first date
            // that matters for reconciliation.
            $table->date('paid_on');

            $table->string('method', 24);
            // The bank reference or cheque number: what reconciliation matches
            // against.
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            // Who recorded it. Null-on-delete, because a payment is a fact
            // about money and outlives the person who typed it in.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['invoice_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
    }
};
