<?php

use App\Domain\Approvals\Actions\DecideApprovalAction;
use App\Domain\Products\Cpq\DiscountRules;
use App\Domain\Products\Enums\BundlePricing;
use App\Domain\Products\Enums\ProductKind;
use App\Domain\Products\Models\PriceBook;
use App\Domain\Products\Models\PriceBookEntry;
use App\Domain\Products\Models\PriceBreak;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductBundleItem;
use App\Domain\Products\Pricing\PriceResolver;
use App\Domain\Sales\Actions\CreateQuoteAction;
use App\Domain\Sales\Actions\RequestDiscountApprovalAction;
use App\Domain\Sales\Actions\SaveQuoteLinesAction;
use App\Domain\Sales\Actions\SendQuoteAction;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Quote;
use App\Domain\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

function priceAt(Product $product, float $quantity, ?PriceBook $book = null): float
{
    return app(PriceResolver::class)->priceFor($product, $book, null, $quantity);
}

function setMaxDiscount(?int $percent): void
{
    app(SettingsManager::class)->set(DiscountRules::SETTING, $percent);
    app(SettingsManager::class)->flush();
}

beforeEach(function () {
    Mail::fake();
    setMaxDiscount(0);
});

// -- Quantity breaks trigger at the right thresholds ---------------------------

test('a break applies at its threshold and not below it', function () {
    $product = Product::factory()->pricedAt(10)->create();
    PriceBreak::factory()->forProduct($product)->at(100, 8.50)->create();

    expect(priceAt($product, 99))->toBe(10.0)
        // At the threshold, not one above it.
        ->and(priceAt($product, 100))->toBe(8.50)
        ->and(priceAt($product, 101))->toBe(8.50);
});

test('the highest threshold at or below the quantity wins', function () {
    // A product with breaks at 10, 100 and 1000 prices an order of 500 at the
    // hundred rate.
    $product = Product::factory()->pricedAt(10)->create();
    PriceBreak::factory()->forProduct($product)->at(10, 9)->create();
    PriceBreak::factory()->forProduct($product)->at(100, 8)->create();
    PriceBreak::factory()->forProduct($product)->at(1000, 7)->create();

    expect(priceAt($product, 1))->toBe(10.0)
        ->and(priceAt($product, 10))->toBe(9.0)
        ->and(priceAt($product, 99))->toBe(9.0)
        ->and(priceAt($product, 100))->toBe(8.0)
        ->and(priceAt($product, 500))->toBe(8.0)
        ->and(priceAt($product, 1000))->toBe(7.0)
        ->and(priceAt($product, 5000))->toBe(7.0);
});

test('a break beats a flat price in the same book', function () {
    // The whole point of "100 or more at 8.50" is that it wins over the 10.00
    // listed beside it.
    $product = Product::factory()->pricedAt(20)->create();
    $book = PriceBook::factory()->create();
    PriceBookEntry::factory()->for_($book, $product, 10)->create();
    PriceBreak::factory()->forProduct($product, $book)->at(100, 8.50)->create();

    expect(priceAt($product, 50, $book))->toBe(10.0)
        ->and(priceAt($product, 100, $book))->toBe(8.50);
});

test("a book's own break beats the catalogue's", function () {
    $product = Product::factory()->pricedAt(20)->create();
    $book = PriceBook::factory()->create();

    PriceBreak::factory()->forProduct($product)->at(100, 15)->create();
    PriceBreak::factory()->forProduct($product, $book)->at(100, 12)->create();

    expect(priceAt($product, 100, $book))->toBe(12.0)
        // And without the book, the catalogue's break still applies.
        ->and(priceAt($product, 100))->toBe(15.0);
});

test('a fractional threshold works, because some things are sold by the hour', function () {
    $product = Product::factory()->service()->pricedAt(100)->create();
    PriceBreak::factory()->forProduct($product)->at(3.5, 80)->create();

    expect(priceAt($product, 3))->toBe(100.0)
        ->and(priceAt($product, 3.5))->toBe(80.0);
});

test('a line on a quote is priced at its quantity break', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $product = Product::factory()->pricedAt(10)->create();
    PriceBreak::factory()->forProduct($product)->at(100, 8.50)->create();

    $quote = app(CreateQuoteAction::class)(['bill_to_name' => 'Acme', 'owner_id' => $user->id]);
    app(SaveQuoteLinesAction::class)($quote, [
        ['product_id' => $product->id, 'quantity' => 100],
    ]);

    $line = $quote->fresh()->lines->first();

    expect((float) $line->unit_price)->toBe(8.50)
        ->and((float) $line->line_total)->toBe(850.0);
});

test('a resolved price says it came from a break', function () {
    $product = Product::factory()->pricedAt(10)->create();
    PriceBreak::factory()->forProduct($product)->at(100, 8.50)->create();

    $resolved = app(PriceResolver::class)->resolve($product, null, null, 100);

    expect($resolved->amount)->toBe(8.50)
        ->and($resolved->fromQuantityBreak())->toBeTrue()
        ->and($resolved->sourceLabel())->toContain('100+');
});

// -- Bundle pricing ----------------------------------------------------------------

/**
 * A bundle of a 100 part and a 50 part, priced however the mode says.
 */
function bundleOf(BundlePricing $mode, ?float $percent = null, float $ownPrice = 120): Product
{
    $bundle = Product::factory()->bundle()->create([
        'list_price' => $ownPrice,
        'bundle_pricing' => $mode->value,
        'bundle_discount_percent' => $percent,
    ]);

    ProductBundleItem::factory()->of($bundle, Product::factory()->pricedAt(100)->create())->create();
    ProductBundleItem::factory()->of($bundle, Product::factory()->pricedAt(50)->create())->create();

    return $bundle->fresh();
}

test('a fixed bundle is priced on its own, whatever its parts cost', function () {
    $bundle = bundleOf(BundlePricing::Fixed, ownPrice: 120);

    // The parts come to 150; the bundle sells for 120, which is the point.
    expect($bundle->componentTotal())->toBe(150.0)
        ->and(priceAt($bundle, 1))->toBe(120.0);
});

test('a summed bundle follows its parts', function () {
    $bundle = bundleOf(BundlePricing::Sum, ownPrice: 999);

    expect(priceAt($bundle, 1))->toBe(150.0);
});

test('a summed bundle keeps following its parts when one changes', function () {
    // The point of choosing that mode: computed rather than copied into
    // list_price, where it would go stale the moment a component moved.
    $bundle = bundleOf(BundlePricing::Sum);

    $part = $bundle->components->first()->product;
    $part->forceFill(['list_price' => 300])->save();

    expect(priceAt($bundle->fresh(), 1))->toBe(350.0);
});

test('a bundle can be its parts less an agreed percentage', function () {
    // "10% off when bought together", expressed once rather than as a fixed
    // price somebody has to remember to update.
    $bundle = bundleOf(BundlePricing::SumLessPercent, percent: 10);

    expect(priceAt($bundle, 1))->toBe(135.0);
});

test('a bundle discount over a hundred per cent does not pay the customer', function () {
    $bundle = bundleOf(BundlePricing::SumLessPercent, percent: 150);

    expect(priceAt($bundle, 1))->toBe(0.0);
});

test('a price book still overrides a computed bundle price', function () {
    // A book is an override list, and a bundle is a product like any other.
    $bundle = bundleOf(BundlePricing::Sum);
    $book = PriceBook::factory()->create();
    PriceBookEntry::factory()->for_($book, $bundle, 99)->create();

    expect(priceAt($bundle, 1, $book))->toBe(99.0)
        ->and(priceAt($bundle, 1))->toBe(150.0);
});

test('bundle pricing is only meaningful for a bundle', function () {
    $product = Product::factory()->pricedAt(100)->create([
        'kind' => ProductKind::Product->value,
        'bundle_pricing' => BundlePricing::Sum->value,
    ]);

    // A plain product has no parts to sum, so it keeps its own price.
    expect(priceAt($product, 1))->toBe(100.0);
});

test('every bundle pricing mode reads as something', function () {
    foreach (BundlePricing::cases() as $mode) {
        expect($mode->label())->not->toBeEmpty()
            ->and($mode->description())->not->toBeEmpty();
    }

    expect(BundlePricing::Fixed->usesComponents())->toBeFalse()
        ->and(BundlePricing::Sum->usesComponents())->toBeTrue()
        ->and(BundlePricing::SumLessPercent->needsPercentage())->toBeTrue()
        ->and(BundlePricing::Sum->needsPercentage())->toBeFalse();
});

// -- Approval on max discount ---------------------------------------------------------

/**
 * A quote discounted by the given percentage on a single 1000 line.
 */
function discountedQuote(float $percent): Quote
{
    $user = auth()->user() ?? User::factory()->create();

    $quote = app(CreateQuoteAction::class)([
        'bill_to_name' => 'Acme Ltd',
        'bill_to_email' => 'buyer@example.com',
        'owner_id' => $user->id,
    ]);

    app(SaveQuoteLinesAction::class)($quote, [
        ['name' => 'Consulting', 'quantity' => 1, 'unit_price' => 1000,
            'discount_type' => DiscountType::Percentage->value, 'discount_value' => $percent],
    ]);

    return $quote->fresh();
}

test('the rule triggers above the limit and not at it', function () {
    $this->actingAs(User::factory()->create());
    setMaxDiscount(15);

    $rules = app(DiscountRules::class);

    expect($rules->needsApproval(discountedQuote(14)))->toBeFalse()
        // At the limit is within it: "approval above 15%" means 15 is allowed.
        ->and($rules->needsApproval(discountedQuote(15)))->toBeFalse()
        ->and($rules->needsApproval(discountedQuote(15.01)))->toBeTrue()
        ->and($rules->needsApproval(discountedQuote(40)))->toBeTrue();
});

test('no limit configured means nothing ever needs approving', function () {
    // An installation that has not set this up must not find its quotes
    // blocked.
    $this->actingAs(User::factory()->create());
    setMaxDiscount(0);

    expect(app(DiscountRules::class)->needsApproval(discountedQuote(90)))->toBeFalse();
});

test('the limit is a proportion of the whole document, not of one line', function () {
    // Ten per cent off one line of twenty is not the concession ten per cent
    // off everything is, and a per-line rule lets somebody give away half a
    // quote in slices that each pass.
    $user = User::factory()->create();
    $this->actingAs($user);
    setMaxDiscount(20);

    $quote = app(CreateQuoteAction::class)(['bill_to_name' => 'Acme', 'owner_id' => $user->id]);

    app(SaveQuoteLinesAction::class)($quote, [
        // Half off a small line, nothing off a large one: 50% on the line,
        // about 4.5% of the document.
        ['name' => 'Small', 'quantity' => 1, 'unit_price' => 100,
            'discount_type' => DiscountType::Percentage->value, 'discount_value' => 50],
        ['name' => 'Large', 'quantity' => 1, 'unit_price' => 1000],
    ]);

    $quote = $quote->fresh();

    expect(app(DiscountRules::class)->discountPercent($quote))->toBe(4.55)
        ->and(app(DiscountRules::class)->needsApproval($quote))->toBeFalse();
});

test('a fixed-amount discount is measured as the proportion it is', function () {
    // A rule written in percentages still catches "500 off a 1000 line".
    $user = User::factory()->create();
    $this->actingAs($user);
    setMaxDiscount(20);

    $quote = app(CreateQuoteAction::class)(['bill_to_name' => 'Acme', 'owner_id' => $user->id]);
    app(SaveQuoteLinesAction::class)($quote, [
        ['name' => 'Consulting', 'quantity' => 1, 'unit_price' => 1000,
            'discount_type' => DiscountType::Amount->value, 'discount_value' => 500],
    ]);

    $quote = $quote->fresh();

    expect(app(DiscountRules::class)->needsApproval($quote))->toBeTrue()
        ->and(app(DiscountRules::class)->linePercent($quote->lines->first()))->toBe(50.0);
});

test('the offending lines are reported worst first', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    setMaxDiscount(10);

    $quote = app(CreateQuoteAction::class)(['bill_to_name' => 'Acme', 'owner_id' => $user->id]);
    app(SaveQuoteLinesAction::class)($quote, [
        ['name' => 'Mild', 'quantity' => 1, 'unit_price' => 100, 'discount_type' => DiscountType::Percentage->value, 'discount_value' => 20],
        ['name' => 'Fine', 'quantity' => 1, 'unit_price' => 100, 'discount_type' => DiscountType::Percentage->value, 'discount_value' => 5],
        ['name' => 'Severe', 'quantity' => 1, 'unit_price' => 100, 'discount_type' => DiscountType::Percentage->value, 'discount_value' => 60],
    ]);

    $offenders = app(DiscountRules::class)->offendingLines($quote->fresh());

    expect($offenders)->toHaveCount(2)
        ->and($offenders[0]['line']->name)->toBe('Severe')
        ->and($offenders[1]['line']->name)->toBe('Mild');
});

test('a quote over the limit cannot be sent until it is approved', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    setMaxDiscount(15);

    $quote = discountedQuote(40);

    expect(fn () => app(SendQuoteAction::class)($quote))->toThrow(RuntimeException::class);

    expect($quote->fresh()->status())->toBe(QuoteStatus::Draft);
    Mail::assertNothingQueued();
});

test('approving the discount lets the quote go', function () {
    $user = User::factory()->create();
    $approver = User::factory()->create();
    $this->actingAs($user);
    setMaxDiscount(15);

    $quote = discountedQuote(40);

    $request = app(RequestDiscountApprovalAction::class)($quote, [$approver->id]);

    // Still blocked while it waits.
    expect(fn () => app(SendQuoteAction::class)($quote))->toThrow(RuntimeException::class);

    app(DecideApprovalAction::class)($request, $approver, true, 'Strategic account.');

    app(SendQuoteAction::class)($quote->fresh());

    expect($quote->fresh()->status())->toBe(QuoteStatus::Sent);
});

test('an approval covers the offer it was given for, not the quote forever', function () {
    // An approval for a 20% discount must not cover a 60% one typed afterwards
    // — including when both happen in the same second, which is why this is
    // matched on what was approved rather than on when.
    $user = User::factory()->create();
    $approver = User::factory()->create();
    $this->actingAs($user);
    setMaxDiscount(15);

    $quote = discountedQuote(20);
    $request = app(RequestDiscountApprovalAction::class)($quote, [$approver->id]);
    app(DecideApprovalAction::class)($request, $approver, true);

    expect(app(RequestDiscountApprovalAction::class)->isApproved($quote->fresh()))->toBeTrue();

    // Somebody deepens the discount afterwards. The approval was for a 20%
    // offer; this is a different one.
    app(SaveQuoteLinesAction::class)($quote->fresh(), [
        ['name' => 'Consulting', 'quantity' => 1, 'unit_price' => 1000,
            'discount_type' => DiscountType::Percentage->value, 'discount_value' => 60],
    ]);

    expect(app(RequestDiscountApprovalAction::class)->isApproved($quote->fresh()))->toBeFalse()
        // And it is blocked again, in the same second as the approval.
        ->and(fn () => app(SendQuoteAction::class)($quote->fresh()))->toThrow(RuntimeException::class);
});

test('a rejected discount leaves the quote unsendable', function () {
    $user = User::factory()->create();
    $approver = User::factory()->create();
    $this->actingAs($user);
    setMaxDiscount(15);

    $quote = discountedQuote(40);
    $request = app(RequestDiscountApprovalAction::class)($quote, [$approver->id]);

    app(DecideApprovalAction::class)($request, $approver, false, 'Too much.');

    expect(fn () => app(SendQuoteAction::class)($quote->fresh()))->toThrow(RuntimeException::class);
});

test('a quote within the limit needs no approval to ask for', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    setMaxDiscount(50);

    $quote = discountedQuote(10);

    expect(fn () => app(RequestDiscountApprovalAction::class)($quote, [User::factory()->create()->id]))
        ->toThrow(RuntimeException::class);
});

test('one approval request at a time', function () {
    $user = User::factory()->create();
    $approver = User::factory()->create();
    $this->actingAs($user);
    setMaxDiscount(15);

    $quote = discountedQuote(40);
    app(RequestDiscountApprovalAction::class)($quote, [$approver->id]);

    expect(fn () => app(RequestDiscountApprovalAction::class)($quote, [$approver->id]))
        ->toThrow(RuntimeException::class);
});

test('the approval reads as something somebody can act on', function () {
    $user = User::factory()->create();
    $approver = User::factory()->create();
    $this->actingAs($user);
    setMaxDiscount(15);

    $quote = discountedQuote(40);
    $request = app(RequestDiscountApprovalAction::class)($quote, [$approver->id]);

    expect($request->summary)->toContain('40%')
        ->and($request->summary)->toContain('15%')
        ->and($request->summary)->toContain($quote->reference())
        // It lands in the same queue as a workflow approval, which is the
        // point of reusing that machinery rather than inventing a second inbox.
        ->and($request->isAwaiting($approver->id))->toBeTrue()
        ->and($request->module)->toBe('quotes');
});
