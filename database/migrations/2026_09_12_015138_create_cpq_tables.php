<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configure-price-quote rules: quantity breaks and bundle pricing.
 *
 * **Breaks are rows, not columns on a price book entry.** A product has as many
 * of them as the commercial arrangement needs — 1+, 10+, 100+, 1000+ — and
 * three nullable "tier" columns would cap it at three and make "which tier
 * applies" a case statement instead of an ordered lookup.
 *
 * A break can belong to a price book or to nothing. Nothing means the
 * catalogue's own break, which applies whenever a more specific one does not —
 * the same override-list idea price books already follow, so there is one rule
 * to learn rather than two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_breaks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Null means this break belongs to the catalogue rather than to a
            // book.
            $table->foreignId('price_book_id')->nullable()->constrained()->cascadeOnDelete();

            /**
             * The quantity at which this price starts applying.
             *
             * Fractional, because a product sold by the hour can have a break
             * at half a day. Three decimals, matching the quantity on a line.
             */
            $table->decimal('min_quantity', 12, 3);

            $table->decimal('price', 15, 2);

            $table->timestamps();

            // One price per quantity per book per product. Two rows at the same
            // threshold would make "the price" a matter of which came back
            // first.
            $table->unique(['product_id', 'price_book_id', 'min_quantity'], 'price_breaks_unique');
            // The lookup: the highest threshold at or below the quantity.
            $table->index(['product_id', 'price_book_id', 'min_quantity']);
        });

        Schema::table('products', function (Blueprint $table) {
            /**
             * How a bundle is priced.
             *
             * Only meaningful for a bundle, and defaulted to `fixed` — which is
             * what every existing bundle already does, so adding this changes
             * nothing about what is stored today.
             */
            $table->string('bundle_pricing', 16)->default('fixed')->after('list_price');

            // The percentage off the components, for the mode that uses one.
            $table->decimal('bundle_discount_percent', 5, 2)->nullable()->after('bundle_pricing');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['bundle_pricing', 'bundle_discount_percent']);
        });

        Schema::dropIfExists('price_breaks');
    }
};
