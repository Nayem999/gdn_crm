<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales orders and purchase orders.
 *
 * Two tables rather than one with a direction flag. They look alike and they
 * are not the same thing: a sales order is a commitment we have made to a
 * customer, a purchase order is one we have made to a supplier, and they are
 * approved by different people, become different documents (an invoice, a bill)
 * and are reported on separately. A `direction` column would put both down one
 * code path and every query would then carry a filter that somebody eventually
 * forgets — which is the query that shows a customer their supplier prices.
 *
 * Both carry the same **snapshot** discipline as quotes: who it was for, what
 * it said and what it came to are copies, because the counterparty has their
 * own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();

            $table->string('number', 32)->unique();

            // Where it came from. Null-on-delete: an order outlives the quote
            // it was raised from, and it carries its own lines regardless.
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();

            $table->string('bill_to_name');
            $table->text('bill_to_address')->nullable();
            $table->string('bill_to_email')->nullable();

            // What the customer called it on their side. The first thing
            // somebody searches for when a customer rings about an order.
            $table->string('customer_reference')->nullable();

            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            $table->string('status', 16);
            $table->string('tax_mode', 16);

            $table->date('order_date');
            $table->date('expected_date')->nullable();

            $table->text('notes')->nullable();

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'id']);
            $table->index(['account_id', 'id']);
            $table->index('quote_id');
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();

            $table->string('number', 32)->unique();

            /**
             * Who we are buying from.
             *
             * An account, because a supplier is an organisation this
             * installation already knows how to store — a separate suppliers
             * table would be a second address book to keep in step. The name is
             * snapshotted all the same, for the same reason every other
             * document snapshots it.
             */
            $table->foreignId('supplier_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('supplier_name');
            $table->text('supplier_address')->nullable();
            $table->string('supplier_email')->nullable();

            // The sales order this was raised to fulfil, when it was. A
            // purchase order often exists on its own, so it is optional.
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            $table->string('status', 16);
            $table->string('tax_mode', 16);

            $table->date('order_date');
            $table->date('expected_date')->nullable();

            $table->text('notes')->nullable();

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);

            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'id']);
            $table->index(['supplier_account_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('sales_orders');
    }
};
