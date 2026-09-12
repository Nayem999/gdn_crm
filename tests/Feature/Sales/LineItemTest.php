<?php

use App\Domain\Deals\Models\Deal;
use App\Domain\Products\Enums\ProductUnit;
use App\Domain\Products\Models\Product;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Enums\TaxMode;
use App\Domain\Sales\Models\DocumentLine;
use App\Domain\Sales\Pricing\LineCalculator;
use App\Domain\Sales\Pricing\LineTotal;

function calculate(
    float $quantity,
    float $unitPrice,
    ?DiscountType $discountType = null,
    float $discountValue = 0,
    float $taxRate = 0,
    TaxMode $taxMode = TaxMode::Exclusive,
): LineTotal {
    return app(LineCalculator::class)->total(
        $quantity, $unitPrice, $discountType, $discountValue, $taxRate, $taxMode,
    );
}

// -- The combinations ---------------------------------------------------------

test('a line totals correctly across discount and tax combinations', function (
    float $quantity,
    float $unitPrice,
    ?DiscountType $discountType,
    float $discountValue,
    float $taxRate,
    float $expectedNet,
    float $expectedTax,
    float $expectedTotal,
) {
    $line = calculate($quantity, $unitPrice, $discountType, $discountValue, $taxRate);

    expect($line->net)->toBe($expectedNet)
        ->and($line->tax)->toBe($expectedTax)
        ->and($line->total)->toBe($expectedTotal)
        // Whatever the inputs, the parts agree with the whole.
        ->and(round($line->net + $line->tax, 2))->toBe($line->total);
})->with([
    // quantity, price, discount type, discount value, tax %, net, tax, total
    'plain' => [1, 100, null, 0, 0, 100.0, 0.0, 100.0],
    'quantity' => [3, 100, null, 0, 0, 300.0, 0.0, 300.0],
    'fractional quantity' => [1.5, 100, null, 0, 0, 150.0, 0.0, 150.0],
    'tax only' => [1, 100, null, 0, 20, 100.0, 20.0, 120.0],
    'percentage discount' => [1, 100, DiscountType::Percentage, 10, 0, 90.0, 0.0, 90.0],
    'fixed discount' => [1, 100, DiscountType::Amount, 25, 0, 75.0, 0.0, 75.0],
    // The order matters: discount first, then tax on what is left. Taxing
    // first would collect 20 rather than 18 — tax on money nobody paid.
    'discount then tax' => [1, 100, DiscountType::Percentage, 10, 20, 90.0, 18.0, 108.0],
    'fixed discount then tax' => [1, 100, DiscountType::Amount, 25, 20, 75.0, 15.0, 90.0],
    'everything at once' => [4, 250, DiscountType::Percentage, 15, 20, 850.0, 170.0, 1020.0],
    'awkward rate' => [1, 100, null, 0, 17.5, 100.0, 17.5, 117.5],
    'zero price' => [5, 0, null, 0, 20, 0.0, 0.0, 0.0],
]);

test('a discount never makes a line negative', function () {
    // A negative line on an invoice is a credit note, which is a different
    // document with different rules — not a line with a minus sign on it.
    $line = calculate(1, 100, DiscountType::Amount, 500);

    expect($line->discount)->toBe(100.0)
        ->and($line->net)->toBe(0.0)
        ->and($line->total)->toBe(0.0);
});

test('a discount over a hundred per cent is treated as a hundred', function () {
    // "110% off" is a typo, and honouring it would pay the customer to take
    // the goods.
    $line = calculate(1, 100, DiscountType::Percentage, 110);

    expect($line->net)->toBe(0.0);
});

test('a line with nothing on it comes to nothing', function () {
    expect(calculate(0, 100)->total)->toBe(0.0)
        ->and(calculate(-1, 100)->total)->toBe(0.0)
        ->and(calculate(1, -100)->total)->toBe(0.0);
});

// -- Tax-inclusive pricing -------------------------------------------------------

test('an inclusive price already contains its tax', function () {
    // 120 at 20% inclusive is 100 net and 20 tax — not 120 net and 24 tax,
    // which is the mistake that makes every inclusive document wrong by a
    // consistent, plausible-looking amount.
    $line = calculate(1, 120, taxRate: 20, taxMode: TaxMode::Inclusive);

    expect($line->net)->toBe(100.0)
        ->and($line->tax)->toBe(20.0)
        ->and($line->total)->toBe(120.0);
});

test('the two tax modes describe the same sale from both ends', function () {
    $exclusive = calculate(1, 100, taxRate: 20, taxMode: TaxMode::Exclusive);
    $inclusive = calculate(1, 120, taxRate: 20, taxMode: TaxMode::Inclusive);

    expect($inclusive->net)->toBe($exclusive->net)
        ->and($inclusive->tax)->toBe($exclusive->tax)
        ->and($inclusive->total)->toBe($exclusive->total);
});

test('an inclusive line discounts before the tax is extracted', function () {
    // 10% off 120 is 108, which contains 18 of tax and 90 of net.
    $line = calculate(1, 120, DiscountType::Percentage, 10, 20, TaxMode::Inclusive);

    expect($line->net)->toBe(90.0)
        ->and($line->tax)->toBe(18.0)
        ->and($line->total)->toBe(108.0);
});

test('an inclusive split always adds back up', function (float $amount) {
    // The tax is taken as the remainder rather than rounded separately, so the
    // net and the tax always come back to the figure that was quoted.
    $line = calculate(1, $amount, taxRate: 20, taxMode: TaxMode::Inclusive);

    expect(round($line->net + $line->tax, 2))->toBe(round($amount, 2))
        ->and($line->total)->toBe(round($amount, 2));
})->with([0.01, 0.05, 1.0, 9.99, 19.99, 100.0, 123.45, 999.99, 12345.67]);

// -- Rounding --------------------------------------------------------------------

test('a document total is the sum of what its lines print', function () {
    // The customer checks the arithmetic by adding up the column they can see.
    // A total computed from unrounded intermediates is right about the money
    // and wrong about the document, which is worse.
    $deal = Deal::factory()->create();

    // Three lines that each round a third of a penny.
    foreach ([0.335, 0.335, 0.335] as $index => $price) {
        DocumentLine::factory()->on($deal)->of(1, $price)->create(['position' => $index]);
    }

    $totals = $deal->totals();
    $lineSum = $deal->lines->sum(fn (DocumentLine $line) => $line->recalculate()->total);

    expect($totals->total)->toBe(round($lineSum, 2));
});

test('each line is rounded before it is added, not after', function () {
    $deal = Deal::factory()->create();

    // 0.125 rounds to 0.13 on a line. Three of them printed are 0.39.
    // Summing first gives 0.375, which rounds to 0.38 — a penny out from what
    // the document shows.
    foreach (range(0, 2) as $index) {
        DocumentLine::factory()->on($deal)->of(1, 0.125)->create(['position' => $index]);
    }

    expect($deal->totals()->total)->toBe(0.39);
});

test('a large quantity at a fractional price rounds once', function () {
    // Rounding the unit price first and multiplying turns a third of a penny
    // into a penny per unit, which on a thousand units is a figure somebody
    // notices.
    $line = calculate(1000, 0.335);

    expect($line->gross)->toBe(335.0);
});

test('tax on a rounded line is taken from the rounded figure', function () {
    // Otherwise the printed net plus the printed tax does not equal the
    // printed total.
    $line = calculate(3, 33.33, taxRate: 20);

    expect($line->net)->toBe(99.99)
        ->and($line->tax)->toBe(20.0)
        ->and($line->total)->toBe(119.99)
        ->and(round($line->net + $line->tax, 2))->toBe($line->total);
});

// -- Document totals ----------------------------------------------------------------

test('a document adds up its lines', function () {
    $deal = Deal::factory()->create();

    DocumentLine::factory()->on($deal)->of(2, 100)->taxedAt(20)->create(['position' => 0]);
    DocumentLine::factory()->on($deal)->of(1, 50)->taxedAt(20)->create(['position' => 1]);

    $totals = $deal->totals();

    expect($totals->net)->toBe(250.0)
        ->and($totals->tax)->toBe(50.0)
        ->and($totals->total)->toBe(300.0)
        ->and($totals->lineCount)->toBe(2);
});

test('a document keeps its discount total separate', function () {
    $deal = Deal::factory()->create();

    DocumentLine::factory()->on($deal)->of(1, 100)->discounted(DiscountType::Percentage, 10)->create();
    DocumentLine::factory()->on($deal)->of(1, 200)->discounted(DiscountType::Amount, 50)->create(['position' => 1]);

    $totals = $deal->totals();

    expect($totals->gross)->toBe(300.0)
        ->and($totals->discount)->toBe(60.0)
        ->and($totals->net)->toBe(240.0)
        ->and($totals->hasDiscount())->toBeTrue();
});

test('a document with mixed tax rates reports each band', function () {
    // A tax authority asks for the twenty-per-cent figure, not the total.
    $deal = Deal::factory()->create();

    DocumentLine::factory()->on($deal)->of(1, 100)->taxedAt(20)->create(['position' => 0]);
    DocumentLine::factory()->on($deal)->of(1, 100)->taxedAt(5)->create(['position' => 1]);
    DocumentLine::factory()->on($deal)->of(1, 100)->taxedAt(20)->create(['position' => 2]);

    $totals = $deal->totals();

    expect($totals->hasMixedTaxRates())->toBeTrue()
        ->and($totals->taxByRate['20.00'])->toBe(40.0)
        ->and($totals->taxByRate['5.00'])->toBe(5.0)
        ->and($totals->tax)->toBe(45.0);
});

test('an untaxed document reports no bands at all', function () {
    $deal = Deal::factory()->create();
    DocumentLine::factory()->on($deal)->of(1, 100)->create();

    $totals = $deal->totals();

    expect($totals->taxByRate)->toBe([])
        ->and($totals->hasTax())->toBeFalse()
        ->and($totals->hasMixedTaxRates())->toBeFalse();
});

test('a document with no lines comes to nothing', function () {
    $deal = Deal::factory()->create();

    expect($deal->totals()->total)->toBe(0.0)
        ->and($deal->totals()->lineCount)->toBe(0);
});

test('totals read the loaded lines rather than querying again', function () {
    // A list of documents that eager-loaded their lines must not query per row.
    $deal = Deal::factory()->create();
    DocumentLine::factory()->on($deal)->of(1, 100)->create();

    $loaded = Deal::query()->with('lines')->whereKey($deal->id)->first();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $loaded->totals();

    $queries = collect(DB::getRawQueryLog())
        ->filter(fn (array $entry): bool => str_contains($entry['raw_query'], 'document_lines'))
        ->count();

    DB::disableQueryLog();

    expect($queries)->toBe(0);
});

// -- The line as a snapshot -----------------------------------------------------------

test('a line keeps saying what it said after the catalogue changes', function () {
    // The customer has a copy of the March quote. A document that changes
    // retrospectively is one nobody can rely on.
    $product = Product::factory()->pricedAt(100)->create(['name' => 'Widget']);
    $deal = Deal::factory()->create();

    $line = DocumentLine::factory()->on($deal)->forProduct($product)->create();

    $product->forceFill(['name' => 'Widget mk II', 'list_price' => 250])->save();

    $line = $line->fresh();

    expect($line->name)->toBe('Widget')
        ->and((float) $line->unit_price)->toBe(100.0);
});

test('a line survives its product leaving the catalogue', function () {
    $product = Product::factory()->create(['name' => 'Discontinued']);
    $deal = Deal::factory()->create();
    $line = DocumentLine::factory()->on($deal)->forProduct($product)->create();

    $product->forceDelete();

    $line = $line->fresh();

    expect($line)->not->toBeNull()
        ->and($line->product_id)->toBeNull()
        // And it still prints, because everything it needs is on the line.
        ->and($line->name)->toBe('Discontinued')
        ->and($line->recalculate()->total)->toBeGreaterThan(0);
});

// -- How a line reads -------------------------------------------------------------------

test('a quantity reads with its unit, singular or plural', function () {
    $deal = Deal::factory()->create();

    $one = DocumentLine::factory()->on($deal)->of(1, 10)->create(['unit' => ProductUnit::Hour->value]);
    $several = DocumentLine::factory()->on($deal)->of(3, 10)->create(['unit' => ProductUnit::Hour->value, 'position' => 1]);
    $fractional = DocumentLine::factory()->on($deal)->of(1.5, 10)->create(['unit' => ProductUnit::Day->value, 'position' => 2]);

    expect($one->quantityLabel())->toBe('1 hour')
        ->and($several->quantityLabel())->toBe('3 hours')
        // No trailing zeros: "1.5 days", never "1.500 days".
        ->and($fractional->quantityLabel())->toBe('1.5 days');
});

test('a discount reads as what was meant', function () {
    $deal = Deal::factory()->create();

    $percent = DocumentLine::factory()->on($deal)->discounted(DiscountType::Percentage, 10)->create();
    $amount = DocumentLine::factory()->on($deal)->discounted(DiscountType::Amount, 25.5)->create(['position' => 1]);
    $none = DocumentLine::factory()->on($deal)->create(['position' => 2]);

    expect($percent->discountLabel())->toBe('10%')
        ->and($amount->discountLabel())->toBe('25.5')
        ->and($none->discountLabel())->toBeNull();
});

// -- The one arithmetic --------------------------------------------------------------------

test('the stored figures are what the calculator would produce', function () {
    // The guard against something writing a line without going through
    // LineCalculator: the totals a document shows would then be a second
    // arithmetic nobody can see.
    $deal = Deal::factory()->create();

    $line = DocumentLine::factory()->on($deal)->of(3, 33.33)->taxedAt(20)->create();

    $computed = $line->recalculate();

    $line->forceFill($computed->toAttributes())->save();
    $line = $line->fresh();

    expect((float) $line->net_total)->toBe($computed->net)
        ->and((float) $line->tax_total)->toBe($computed->tax)
        ->and((float) $line->line_total)->toBe($computed->total);
});

test('every discount type and tax mode reads as something', function () {
    foreach (DiscountType::cases() as $type) {
        expect($type->label())->not->toBeEmpty();
    }

    foreach (TaxMode::cases() as $mode) {
        expect($mode->label())->not->toBeEmpty();
    }

    expect(TaxMode::Inclusive->includesTax())->toBeTrue()
        ->and(TaxMode::Exclusive->includesTax())->toBeFalse();
});

test('a deal totals the products attached to it', function () {
    // 3.2 asked for products attached to a deal; this is what that is, using
    // the same lines and the same calculator a quote will.
    $deal = Deal::factory()->create();
    $product = Product::factory()->pricedAt(500)->create();

    DocumentLine::factory()->on($deal)->forProduct($product)->of(2, 500)->taxedAt(20)->create();

    expect($deal->totals()->total)->toBe(1200.0)
        ->and($deal->lines)->toHaveCount(1);
});
