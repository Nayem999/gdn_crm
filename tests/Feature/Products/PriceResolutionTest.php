<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Products\Actions\SetDefaultPriceBookAction;
use App\Domain\Products\Models\PriceBook;
use App\Domain\Products\Models\PriceBookEntry;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Pricing\PriceResolver;
use App\Livewire\Products\PriceBooks;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function priceOf(Product $product, ?PriceBook $book = null, ?Carbon $on = null): float
{
    return app(PriceResolver::class)->priceFor($product, $book, $on);
}

/**
 * @param  array<int, string>  $permissions
 */
function pricingUser(array $permissions = ['products.view', 'products.pricing']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

// -- The order ------------------------------------------------------------------

test('a product with no price books is charged at its list price', function () {
    $product = Product::factory()->pricedAt(100)->create();

    expect(priceOf($product))->toBe(100.0);
});

test('the named book wins over the list price', function () {
    $product = Product::factory()->pricedAt(100)->create();
    $reseller = PriceBook::factory()->create();
    PriceBookEntry::factory()->for_($reseller, $product, 75)->create();

    expect(priceOf($product, $reseller))->toBe(75.0)
        // And the catalogue price is untouched: a book overrides, it does not
        // rewrite.
        ->and($product->fresh()->listPrice())->toBe(100.0);
});

test('the named book wins over the default book', function () {
    $product = Product::factory()->pricedAt(100)->create();

    $default = PriceBook::factory()->default()->create();
    PriceBookEntry::factory()->for_($default, $product, 90)->create();

    $reseller = PriceBook::factory()->create();
    PriceBookEntry::factory()->for_($reseller, $product, 75)->create();

    expect(priceOf($product, $reseller))->toBe(75.0);
});

test('the default book applies when no book is named', function () {
    $product = Product::factory()->pricedAt(100)->create();

    $default = PriceBook::factory()->default()->create();
    PriceBookEntry::factory()->for_($default, $product, 90)->create();

    expect(priceOf($product))->toBe(90.0);
});

test('a named book falls through to the default for what it does not list', function () {
    // A book is an override list, not a complete catalogue. Somebody building a
    // reseller book adds the twenty products that differ; the other four
    // hundred must keep their usual price rather than vanish.
    $listed = Product::factory()->pricedAt(100)->create();
    $unlisted = Product::factory()->pricedAt(200)->create();

    $default = PriceBook::factory()->default()->create();
    PriceBookEntry::factory()->for_($default, $listed, 90)->create();
    PriceBookEntry::factory()->for_($default, $unlisted, 180)->create();

    $reseller = PriceBook::factory()->create();
    PriceBookEntry::factory()->for_($reseller, $listed, 75)->create();

    expect(priceOf($listed, $reseller))->toBe(75.0)
        ->and(priceOf($unlisted, $reseller))->toBe(180.0);
});

test('a product in no book at all falls all the way through to the catalogue', function () {
    $product = Product::factory()->pricedAt(100)->create();

    PriceBook::factory()->default()->create();
    $reseller = PriceBook::factory()->create();

    expect(priceOf($product, $reseller))->toBe(100.0);
});

// -- Books that do not apply --------------------------------------------------------

test('a switched-off book prices nothing', function () {
    // Stepped over rather than treated as pricing at nothing — that is the
    // difference between a promotion ending and every product becoming free.
    $product = Product::factory()->pricedAt(100)->create();
    $book = PriceBook::factory()->inactive()->create();
    PriceBookEntry::factory()->for_($book, $product, 10)->create();

    expect(priceOf($product, $book))->toBe(100.0);
});

test('a switched-off default book is stepped over too', function () {
    $product = Product::factory()->pricedAt(100)->create();
    $default = PriceBook::factory()->default()->inactive()->create();
    PriceBookEntry::factory()->for_($default, $product, 10)->create();

    expect(priceOf($product))->toBe(100.0);
});

test('a dated book applies only inside its window, inclusively', function () {
    // Somebody writing "valid to the 31st" means the 31st, and the alternative
    // surprises them on the last day of every promotion.
    $product = Product::factory()->pricedAt(100)->create();

    $sale = PriceBook::factory()->validBetween(
        Carbon::parse('2026-10-01'),
        Carbon::parse('2026-10-31'),
    )->create();

    PriceBookEntry::factory()->for_($sale, $product, 60)->create();

    expect(priceOf($product, $sale, Carbon::parse('2026-09-30')))->toBe(100.0)
        ->and(priceOf($product, $sale, Carbon::parse('2026-10-01')))->toBe(60.0)
        ->and(priceOf($product, $sale, Carbon::parse('2026-10-15')))->toBe(60.0)
        ->and(priceOf($product, $sale, Carbon::parse('2026-10-31')))->toBe(60.0)
        ->and(priceOf($product, $sale, Carbon::parse('2026-11-01')))->toBe(100.0);
});

test('an open-ended book applies whenever', function () {
    $product = Product::factory()->pricedAt(100)->create();
    $book = PriceBook::factory()->validBetween(null, null)->create();
    PriceBookEntry::factory()->for_($book, $product, 60)->create();

    expect(priceOf($product, $book, Carbon::parse('2020-01-01')))->toBe(60.0)
        ->and(priceOf($product, $book, Carbon::parse('2040-01-01')))->toBe(60.0);
});

test('an expired named book falls through to the default', function () {
    $product = Product::factory()->pricedAt(100)->create();

    $default = PriceBook::factory()->default()->create();
    PriceBookEntry::factory()->for_($default, $product, 90)->create();

    $sale = PriceBook::factory()->validBetween(null, Carbon::parse('2026-10-01'))->create();
    PriceBookEntry::factory()->for_($sale, $product, 60)->create();

    expect(priceOf($product, $sale, Carbon::parse('2026-11-01')))->toBe(90.0);
});

// -- What the answer says about itself ------------------------------------------------

test('a resolved price says where it came from', function () {
    // A screen labelling a price "reseller" by re-deriving the order could
    // label a figure it did not produce.
    $product = Product::factory()->pricedAt(100)->create();
    $reseller = PriceBook::factory()->create(['name' => 'Reseller prices']);
    PriceBookEntry::factory()->for_($reseller, $product, 75)->create();

    $resolved = app(PriceResolver::class)->resolve($product, $reseller);

    expect($resolved->amount)->toBe(75.0)
        ->and($resolved->fromCatalogue())->toBeFalse()
        ->and($resolved->sourceLabel())->toBe('Reseller prices');

    $catalogue = app(PriceResolver::class)->resolve(Product::factory()->pricedAt(50)->create());

    expect($catalogue->fromCatalogue())->toBeTrue()
        ->and($catalogue->sourceLabel())->toBe('Catalogue price');
});

// -- Lines and batches --------------------------------------------------------------

test('a line is rounded once, at the end', function () {
    // The stored price is to the penny, so the rounding worth pinning is the
    // one at the end of the line rather than one inside the unit price.
    $product = Product::factory()->pricedAt(0.33)->create();

    expect(app(PriceResolver::class)->lineTotal($product, 1000))->toBe(330.0)
        // And a fractional quantity does not leave a fraction of a penny on
        // the line.
        ->and(app(PriceResolver::class)->lineTotal($product, 1.5))->toBe(0.50);
});

test('many products are priced in one pass, with the same answers', function () {
    $inBook = Product::factory()->pricedAt(100)->create();
    $inDefault = Product::factory()->pricedAt(200)->create();
    $inNeither = Product::factory()->pricedAt(300)->create();

    $default = PriceBook::factory()->default()->create();
    PriceBookEntry::factory()->for_($default, $inDefault, 180)->create();
    PriceBookEntry::factory()->for_($default, $inBook, 95)->create();

    $reseller = PriceBook::factory()->create();
    PriceBookEntry::factory()->for_($reseller, $inBook, 75)->create();

    $map = app(PriceResolver::class)->priceMap([$inBook, $inDefault, $inNeither], $reseller);

    expect($map[$inBook->id])->toBe(75.0)
        ->and($map[$inDefault->id])->toBe(180.0)
        ->and($map[$inNeither->id])->toBe(300.0);

    // The same answers the one-at-a-time path gives, which is the only reason
    // the fast path is safe to use.
    foreach ([$inBook, $inDefault, $inNeither] as $product) {
        expect($map[$product->id])->toBe(priceOf($product, $reseller));
    }
});

test('pricing nothing asks nothing', function () {
    expect(app(PriceResolver::class)->priceMap([]))->toBe([]);
});

// -- The default book ------------------------------------------------------------------

test('making a book the default takes it from the previous one', function () {
    $old = PriceBook::factory()->default()->create();
    $new = PriceBook::factory()->create();

    app(SetDefaultPriceBookAction::class)($new);

    expect($new->fresh()->is_default)->toBeTrue()
        ->and($old->fresh()->is_default)->toBeFalse()
        ->and(PriceBook::query()->where('is_default', true)->count())->toBe(1);
});

test('a book that cannot apply cannot be the default', function () {
    // An inactive default silently does nothing while the screen still shows a
    // book selected.
    $off = PriceBook::factory()->inactive()->create();
    $expired = PriceBook::factory()->validBetween(null, Carbon::now()->subDay())->create();

    expect(fn () => app(SetDefaultPriceBookAction::class)($off))->toThrow(RuntimeException::class);
    expect(fn () => app(SetDefaultPriceBookAction::class)($expired))->toThrow(RuntimeException::class);

    expect(PriceBook::query()->where('is_default', true)->count())->toBe(0);
});

test('there is no default until somebody sets one', function () {
    PriceBook::factory()->count(3)->create();

    expect(PriceBook::default())->toBeNull();
});

// -- The screen ---------------------------------------------------------------------------

test('price books need their own permission to change', function () {
    // Deciding what the company charges is administration, not catalogue work.
    $reader = pricingUser(['products.view']);
    $book = PriceBook::factory()->create();

    expect($reader->can('viewAny', PriceBook::class))->toBeTrue()
        ->and($reader->can('update', $book))->toBeFalse()
        ->and($reader->can('create', PriceBook::class))->toBeFalse();

    expect(pricingUser()->can('update', $book))->toBeTrue();
});

test('the screen saves a book and its prices', function () {
    $user = pricingUser();
    $product = Product::factory()->pricedAt(100)->create();

    $screen = Livewire::actingAs($user)
        ->test(PriceBooks::class)
        ->call('add')
        ->set('name', 'Reseller prices')
        ->call('save')
        ->assertHasNoErrors();

    $book = PriceBook::query()->sole();

    $screen->call('open', $book->id)
        ->set('entryProductId', (string) $product->id)
        ->set('entryPrice', '75')
        ->call('addEntry')
        ->assertHasNoErrors();

    expect(priceOf($product, $book->fresh()))->toBe(75.0);
});

test('setting a price twice changes it rather than failing', function () {
    $user = pricingUser();
    $product = Product::factory()->create();
    $book = PriceBook::factory()->create();

    $screen = Livewire::actingAs($user)
        ->test(PriceBooks::class)
        ->call('open', $book->id);

    $screen->set('entryProductId', (string) $product->id)->set('entryPrice', '75')->call('addEntry');
    $screen->set('entryProductId', (string) $product->id)->set('entryPrice', '65')->call('addEntry');

    expect(PriceBookEntry::query()->count())->toBe(1)
        ->and(priceOf($product, $book))->toBe(65.0);
});

test('a window that ends before it starts is refused', function () {
    Livewire::actingAs(pricingUser())
        ->test(PriceBooks::class)
        ->call('add')
        ->set('name', 'Backwards')
        ->set('validFrom', '2026-10-31')
        ->set('validTo', '2026-10-01')
        ->call('save')
        ->assertHasErrors('validTo');
});

test('an entry id from another book cannot be removed through this one', function () {
    $user = pricingUser();
    $mine = PriceBook::factory()->create();
    $theirs = PriceBook::factory()->create();
    $entry = PriceBookEntry::factory()->for_($theirs, Product::factory()->create(), 50)->create();

    Livewire::actingAs($user)
        ->test(PriceBooks::class)
        ->call('open', $mine->id)
        ->call('removeEntry', $entry->id);

    expect(PriceBookEntry::query()->whereKey($entry->id)->exists())->toBeTrue();
});

test('removing a book leaves its products at the catalogue price', function () {
    $user = pricingUser();
    $product = Product::factory()->pricedAt(100)->create();
    $book = PriceBook::factory()->create();
    PriceBookEntry::factory()->for_($book, $product, 75)->create();

    Livewire::actingAs($user)
        ->test(PriceBooks::class)
        ->call('delete', $book->id);

    expect(priceOf($product))->toBe(100.0);
});

test('the screen offers only products not already in the open book', function () {
    $user = pricingUser();
    $already = Product::factory()->ownedBy($user)->create(['name' => 'Already priced']);
    Product::factory()->ownedBy($user)->create(['name' => 'Not yet priced']);
    $book = PriceBook::factory()->create();
    PriceBookEntry::factory()->for_($book, $already, 50)->create();

    $options = Livewire::actingAs($user)
        ->test(PriceBooks::class)
        ->call('open', $book->id)
        ->instance()
        ->addableProducts();

    expect($options)->toContain('Not yet priced')
        ->and($options)->not->toContain('Already priced');
});
