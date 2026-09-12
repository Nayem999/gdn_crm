<?php

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Accounts\Models\Account;
use App\Domain\Products\Models\Product;
use App\Domain\Sales\Actions\ChangePurchaseOrderStatusAction;
use App\Domain\Sales\Actions\ChangeQuoteStatusAction;
use App\Domain\Sales\Actions\ChangeSalesOrderStatusAction;
use App\Domain\Sales\Actions\ConvertQuoteToOrderAction;
use App\Domain\Sales\Actions\CreatePurchaseOrderAction;
use App\Domain\Sales\Actions\CreateQuoteAction;
use App\Domain\Sales\Actions\SaveQuoteLinesAction;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Enums\PurchaseOrderStatus;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Enums\SalesOrderStatus;
use App\Domain\Sales\Enums\TaxMode;
use App\Domain\Sales\Models\DocumentLine;
use App\Domain\Sales\Models\PurchaseOrder;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\SalesOrder;
use App\Models\User;

/**
 * An accepted quote with lines, ready to convert.
 *
 * @param  array<int, array<string, mixed>>  $lines
 */
function acceptedQuote(array $lines = []): Quote
{
    $this_user = User::factory()->create();

    $quote = app(CreateQuoteAction::class)([
        'bill_to_name' => 'Acme Ltd',
        'bill_to_email' => 'buyer@example.com',
        'owner_id' => $this_user->id,
    ]);

    app(SaveQuoteLinesAction::class)($quote, $lines === [] ? [
        ['name' => 'Consulting', 'quantity' => 2, 'unit_price' => 500, 'tax_rate' => 20],
        ['name' => 'Licence', 'quantity' => 3, 'unit_price' => 100, 'tax_rate' => 20, 'discount_type' => DiscountType::Percentage->value, 'discount_value' => 10],
    ] : $lines);

    app(ChangeQuoteStatusAction::class)($quote, QuoteStatus::Sent);

    return app(ChangeQuoteStatusAction::class)($quote->fresh(), QuoteStatus::Accepted);
}

// -- The headline requirement: conversion preserves the lines ----------------------

test('converting an accepted quote copies every line exactly', function () {
    $quote = acceptedQuote();

    $order = app(ConvertQuoteToOrderAction::class)($quote);

    expect($order->lines)->toHaveCount($quote->lines->count());

    foreach ($quote->lines as $index => $original) {
        $copied = $order->lines[$index];

        expect($copied->name)->toBe($original->name)
            ->and($copied->quantity())->toBe($original->quantity())
            ->and((float) $copied->unit_price)->toBe((float) $original->unit_price)
            ->and($copied->discount_type)->toBe($original->discount_type)
            ->and((float) $copied->discount_value)->toBe((float) $original->discount_value)
            ->and((float) $copied->tax_rate)->toBe((float) $original->tax_rate)
            // The figures too, not a recalculation: what the customer accepted
            // is what they are owed.
            ->and((float) $copied->net_total)->toBe((float) $original->net_total)
            ->and((float) $copied->tax_total)->toBe((float) $original->tax_total)
            ->and((float) $copied->line_total)->toBe((float) $original->line_total);
    }
});

test('the order totals exactly what the quote totalled', function () {
    $quote = acceptedQuote();

    $order = app(ConvertQuoteToOrderAction::class)($quote);

    expect((float) $order->subtotal)->toBe((float) $quote->subtotal)
        ->and((float) $order->discount_total)->toBe((float) $quote->discount_total)
        ->and((float) $order->tax_total)->toBe((float) $quote->tax_total)
        ->and((float) $order->total)->toBe((float) $quote->total)
        // And the header still agrees with its own rows.
        ->and((float) $order->total)->toBe($order->totals()->total);
});

test('an order does not reprice itself when the catalogue moves', function () {
    // What the customer accepted is what they are owed. Recomputing from the
    // product would quietly reprice the order.
    $product = Product::factory()->pricedAt(100)->create();

    $quote = acceptedQuote([
        ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100],
    ]);

    $product->forceFill(['list_price' => 250])->save();

    $order = app(ConvertQuoteToOrderAction::class)($quote);

    expect((float) $order->lines->first()->unit_price)->toBe(100.0)
        ->and((float) $order->total)->toBe(200.0);
});

test('an order carries the quote it came from and the customer it is for', function () {
    $account = Account::factory()->create(['name' => 'Acme Ltd']);

    $quote = acceptedQuote();
    $quote->forceFill(['account_id' => $account->id])->save();

    $order = app(ConvertQuoteToOrderAction::class)($quote->fresh(), 'PO-12345');

    expect($order->quote_id)->toBe($quote->id)
        ->and($order->account_id)->toBe($account->id)
        ->and($order->bill_to_name)->toBe($quote->bill_to_name)
        ->and($order->customer_reference)->toBe('PO-12345')
        ->and($order->status())->toBe(SalesOrderStatus::Draft);
});

test('a tax-inclusive quote converts to a tax-inclusive order', function () {
    $quote = acceptedQuote([['name' => 'Inclusive', 'quantity' => 1, 'unit_price' => 120, 'tax_rate' => 20]]);
    $quote->forceFill(['tax_mode' => TaxMode::Inclusive->value])->save();
    app(SaveQuoteLinesAction::class)($quote->fresh(), [
        ['name' => 'Inclusive', 'quantity' => 1, 'unit_price' => 120, 'tax_rate' => 20],
    ]);

    $order = app(ConvertQuoteToOrderAction::class)($quote->fresh());

    expect($order->taxMode())->toBe(TaxMode::Inclusive)
        ->and((float) $order->total)->toBe(120.0)
        ->and((float) $order->subtotal)->toBe(100.0);
});

// -- What cannot be converted ----------------------------------------------------

test('only an accepted quote becomes an order', function (string $status) {
    // An order raised from a quote nobody accepted is a commitment nobody made.
    $quote = Quote::factory()->create(['status' => $status]);

    expect(fn () => app(ConvertQuoteToOrderAction::class)($quote))
        ->toThrow(RuntimeException::class);

    expect(SalesOrder::query()->count())->toBe(0);
})->with(array_values(array_diff(
    array_column(QuoteStatus::cases(), 'value'),
    [QuoteStatus::Accepted->value],
)));

test('a quote converts once and only once', function () {
    // Two orders from one quote is the customer being asked to pay twice.
    $quote = acceptedQuote();

    $first = app(ConvertQuoteToOrderAction::class)($quote);

    expect(fn () => app(ConvertQuoteToOrderAction::class)($quote))
        ->toThrow(RuntimeException::class);

    expect(SalesOrder::query()->count())->toBe(1)
        ->and(SalesOrder::query()->sole()->id)->toBe($first->id);
});

test('the refusal names the order that already exists', function () {
    $quote = acceptedQuote();
    $order = app(ConvertQuoteToOrderAction::class)($quote);

    try {
        app(ConvertQuoteToOrderAction::class)($quote);
        $this->fail('The conversion should have been refused.');
    } catch (RuntimeException $refused) {
        expect($refused->getMessage())->toContain($order->number);
    }
});

test('an order survives the quote it came from being removed', function () {
    $quote = acceptedQuote();
    $order = app(ConvertQuoteToOrderAction::class)($quote);

    $quote->forceDelete();

    $order = $order->fresh();

    expect($order)->not->toBeNull()
        ->and($order->quote_id)->toBeNull()
        // It stands on its own: its lines and its snapshot came with it.
        ->and($order->lines)->toHaveCount(2)
        ->and($order->bill_to_name)->toBe('Acme Ltd');
});

// -- Sales order status ------------------------------------------------------------

test('a sales order moves through the statuses it is allowed, and no others', function (string $from) {
    $source = SalesOrderStatus::from($from);
    $allowed = $source->allowedTransitions();

    foreach ($allowed as $target) {
        $order = SalesOrder::factory()->withStatus($source)->create();

        expect(app(ChangeSalesOrderStatusAction::class)($order, $target)->status())->toBe($target);
    }

    foreach (SalesOrderStatus::cases() as $target) {
        if (in_array($target, $allowed, true) || $target === $source) {
            continue;
        }

        $order = SalesOrder::factory()->withStatus($source)->create();

        expect(fn () => app(ChangeSalesOrderStatusAction::class)($order, $target))
            ->toThrow(RuntimeException::class);
    }
})->with(array_column(SalesOrderStatus::cases(), 'value'));

test('a confirmed order is stamped with when it was confirmed', function () {
    $order = SalesOrder::factory()->create();

    $order = app(ChangeSalesOrderStatusAction::class)($order, SalesOrderStatus::Confirmed);

    expect($order->confirmed_at)->not->toBeNull()
        ->and($order->fulfilled_at)->toBeNull();
});

test('only a confirmed or fulfilled order can be invoiced', function () {
    // Invoicing something nobody confirmed is how a customer receives a bill
    // for an order they never placed.
    expect(SalesOrderStatus::Draft->canBeInvoiced())->toBeFalse()
        ->and(SalesOrderStatus::Cancelled->canBeInvoiced())->toBeFalse()
        ->and(SalesOrderStatus::Confirmed->canBeInvoiced())->toBeTrue()
        ->and(SalesOrderStatus::Fulfilled->canBeInvoiced())->toBeTrue();
});

// -- Purchase orders -----------------------------------------------------------------

test('a purchase order snapshots its supplier', function () {
    // A purchase order sent last year says who it was sent to, not who that
    // account is called now.
    $supplier = Account::factory()->create(['name' => 'Parts Supplier Ltd', 'email' => 'sales@supplier.example']);
    $user = User::factory()->create();

    $order = app(CreatePurchaseOrderAction::class)([
        'supplier_account_id' => $supplier->id,
        'owner_id' => $user->id,
    ]);

    expect($order->supplier_name)->toBe('Parts Supplier Ltd')
        ->and($order->supplier_email)->toBe('sales@supplier.example')
        ->and($order->number)->toMatch('/^PO-\d{4}-\d{4}$/');

    $supplier->forceFill(['name' => 'Parts Holdings'])->save();

    expect($order->fresh()->supplier_name)->toBe('Parts Supplier Ltd');
});

test('a purchase order carries lines and totals like any other document', function () {
    $user = User::factory()->create();

    $order = app(CreatePurchaseOrderAction::class)(['supplier_name' => 'Parts Ltd', 'owner_id' => $user->id]);

    app(SaveQuoteLinesAction::class)($order, [
        ['name' => 'Widgets', 'quantity' => 100, 'unit_price' => 2.5, 'tax_rate' => 20],
    ]);

    $order = $order->fresh();

    expect((float) $order->subtotal)->toBe(250.0)
        ->and((float) $order->tax_total)->toBe(50.0)
        ->and((float) $order->total)->toBe(300.0);
});

test('a purchase order can be raised against the sales order it fulfils', function () {
    $user = User::factory()->create();
    $salesOrder = SalesOrder::factory()->create();

    $order = app(CreatePurchaseOrderAction::class)([
        'supplier_name' => 'Parts Ltd',
        'sales_order_id' => $salesOrder->id,
        'owner_id' => $user->id,
    ]);

    expect($order->sales_order_id)->toBe($salesOrder->id)
        ->and($order->salesOrder->is($salesOrder))->toBeTrue();
});

test('a purchase order moves through its own statuses, and no others', function (string $from) {
    $source = PurchaseOrderStatus::from($from);
    $allowed = $source->allowedTransitions();

    foreach ($allowed as $target) {
        $order = PurchaseOrder::factory()->withStatus($source)->create();

        expect(app(ChangePurchaseOrderStatusAction::class)($order, $target)->status())->toBe($target);
    }

    foreach (PurchaseOrderStatus::cases() as $target) {
        if (in_array($target, $allowed, true) || $target === $source) {
            continue;
        }

        $order = PurchaseOrder::factory()->withStatus($source)->create();

        expect(fn () => app(ChangePurchaseOrderStatusAction::class)($order, $target))
            ->toThrow(RuntimeException::class);
    }
})->with(array_column(PurchaseOrderStatus::cases(), 'value'));

test('order numbers are sequential and distinct between the two kinds', function () {
    $user = User::factory()->create();

    $first = app(CreatePurchaseOrderAction::class)(['supplier_name' => 'A', 'owner_id' => $user->id]);
    $second = app(CreatePurchaseOrderAction::class)(['supplier_name' => 'B', 'owner_id' => $user->id]);

    $quote = acceptedQuote();
    $salesOrder = app(ConvertQuoteToOrderAction::class)($quote);

    expect($first->number)->toEndWith('0001')
        ->and($second->number)->toEndWith('0002')
        // Their own counter, so a purchase order does not consume a sales
        // order's number.
        ->and($salesOrder->number)->toEndWith('0001')
        ->and($salesOrder->number)->toStartWith('SO-');
});

// -- Both kinds ------------------------------------------------------------------------

test('every order status reads as something and has a colour', function () {
    foreach (SalesOrderStatus::cases() as $status) {
        expect($status->label())->not->toBeEmpty()->and($status->color())->not->toBeEmpty();
    }

    foreach (PurchaseOrderStatus::cases() as $status) {
        expect($status->label())->not->toBeEmpty()->and($status->color())->not->toBeEmpty();
    }
});

test('the order permissions are declared in the catalogue', function (string $permission) {
    expect(PermissionCatalogue::has($permission))->toBeTrue();
})->with(['orders.view', 'orders.create', 'orders.update', 'orders.delete', 'orders.export', 'orders.purchase']);

test('an order line is still a snapshot after conversion', function () {
    // The line does not depend on the product, on either document.
    $product = Product::factory()->create(['name' => 'Widget']);

    $quote = acceptedQuote([['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]]);
    $order = app(ConvertQuoteToOrderAction::class)($quote);

    $product->forceDelete();

    $line = DocumentLine::query()
        ->where('document_type', $order->getMorphClass())
        ->where('document_id', $order->id)
        ->sole();

    expect($line->product_id)->toBeNull()
        ->and($line->name)->toBe('Widget')
        ->and((float) $line->line_total)->toBe(50.0);
});
