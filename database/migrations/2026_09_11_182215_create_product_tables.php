<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the company sells, and what it charges for it.
 *
 * **A bundle is a product.** It has a row in `products` like anything else, with
 * `kind` saying what it is and `product_bundle_items` saying what is in it. The
 * alternative — a `bundles` table beside `products` — would mean every place
 * that sells something (a quote line in 6.2, a deal's attached products, a
 * price book entry) needs two branches and two foreign keys, and the first one
 * to forget the second branch silently cannot sell bundles.
 *
 * **Prices are decimal, never float.** A float cannot hold 0.1, and a quote
 * total that is out by a hundredth is a quote somebody has to explain.
 * decimal(15,2) matches `deals.value`, which is already the money shape here.
 *
 * Single currency throughout, from the company profile. Price books vary the
 * *price*, not the currency — multi-currency is a different feature with its own
 * rate table and its own rounding rules, and pretending a `currency` column
 * amounts to having it would be worse than not having it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            /**
             * The catalogue code. Unique when present, and nullable because
             * plenty of services never get one — a unique index tolerates
             * repeated NULLs, which is exactly the behaviour wanted here.
             */
            $table->string('sku', 64)->nullable()->unique();

            $table->string('kind', 16);
            $table->string('unit', 16);

            $table->text('description')->nullable();
            $table->string('category')->nullable();

            // What it costs us, and what it lists at. Cost is nullable because
            // a service often has no unit cost, and a margin nobody can compute
            // is better than a zero that looks computed.
            $table->decimal('cost_price', 15, 2)->nullable();
            $table->decimal('list_price', 15, 2)->default(0);

            // Per-product, because a catalogue routinely mixes rates — a
            // service at one rate, a physical good at another, an exempt item
            // at none. Null means "use whatever the document decides".
            $table->decimal('tax_rate', 5, 2)->nullable();

            $table->boolean('is_active')->default(true);

            // Visibility follows the owner, like every other business model.
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'name']);
            $table->index(['kind', 'is_active']);
            $table->index('category');
        });

        Schema::create('price_books', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->text('description')->nullable();

            // Exactly one default, kept so by SetDefaultPriceBookAction. It is
            // what prices resolve against when a document names no book.
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            /**
             * A seasonal or promotional book stops applying on its own rather
             * than needing somebody to remember to switch it off. Both nullable:
             * most books are open-ended.
             */
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'name']);
        });

        Schema::create('price_book_entries', function (Blueprint $table) {
            $table->id();

            // An entry has no meaning without its book, and none without its
            // product, so both cascade.
            $table->foreignId('price_book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->decimal('price', 15, 2);

            $table->timestamps();

            // One price per product per book. Two rows would make "the price"
            // a matter of which the database returned first.
            $table->unique(['price_book_id', 'product_id']);
        });

        Schema::create('product_bundle_items', function (Blueprint $table) {
            $table->id();

            // The bundle, and one thing in it. Both point at `products`.
            $table->foreignId('bundle_id')->constrained('products')->cascadeOnDelete();

            // Restricted, not cascaded: removing a product that is inside a
            // bundle would quietly change what that bundle contains, and what
            // it is worth. The catalogue screen offers deactivation first.
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            // Fractional, because a bundle can contain half a day of setup.
            $table->decimal('quantity', 12, 3)->default(1);

            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->unique(['bundle_id', 'product_id']);
            $table->index(['bundle_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_bundle_items');
        Schema::dropIfExists('price_book_entries');
        Schema::dropIfExists('price_books');
        Schema::dropIfExists('products');
    }
};
