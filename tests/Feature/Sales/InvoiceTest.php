<?php

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Sales\Actions\ChangeInvoiceStatusAction;
use App\Domain\Sales\Actions\ChangeQuoteStatusAction;
use App\Domain\Sales\Actions\ChangeSalesOrderStatusAction;
use App\Domain\Sales\Actions\ConvertOrderToInvoiceAction;
use App\Domain\Sales\Actions\ConvertQuoteToOrderAction;
use App\Domain\Sales\Actions\CreateQuoteAction;
use App\Domain\Sales\Actions\RecordPaymentAction;
use App\Domain\Sales\Actions\SaveQuoteLinesAction;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\PaymentState;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Enums\SalesOrderStatus;
use App\Domain\Sales\Models\DocumentLine;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\SalesOrder;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * An issued invoice for a known total, ready to take money.
 */
function issuedInvoice(float $total = 1000): Invoice
{
    $invoice = Invoice::factory()->totalling($total)->create();

    DocumentLine::factory()->on($invoice)->of(1, $total)->create();

    return app(ChangeInvoiceStatusAction::class)($invoice, InvoiceStatus::Issued);
}

function pay(Invoice $invoice, float $amount, ?string $on = null): Payment
{
    return app(RecordPaymentAction::class)($invoice, [
        'amount' => $amount,
        'paid_on' => $on ?? now()->toDateString(),
        'method' => PaymentMethod::BankTransfer->value,
    ]);
}

// -- Partial and full payment states -------------------------------------------

test('an invoice with no payments is unpaid', function () {
    $invoice = issuedInvoice(1000);

    expect($invoice->paymentState())->toBe(PaymentState::Unpaid)
        ->and($invoice->outstanding())->toBe(1000.0)
        ->and($invoice->paid_at)->toBeNull();
});

test('a payment for part of it makes it part paid', function () {
    $invoice = issuedInvoice(1000);

    pay($invoice, 400);

    $invoice = $invoice->fresh();

    expect((float) $invoice->amount_paid)->toBe(400.0)
        ->and($invoice->paymentState())->toBe(PaymentState::PartiallyPaid)
        ->and($invoice->outstanding())->toBe(600.0)
        // Not settled, so no paid stamp.
        ->and($invoice->paid_at)->toBeNull();
});

test('payments that come to the total make it paid', function () {
    $invoice = issuedInvoice(1000);

    pay($invoice, 400);
    pay($invoice, 600);

    $invoice = $invoice->fresh();

    expect((float) $invoice->amount_paid)->toBe(1000.0)
        ->and($invoice->paymentState())->toBe(PaymentState::Paid)
        ->and($invoice->outstanding())->toBe(0.0)
        ->and($invoice->paid_at)->not->toBeNull();
});

test('an invoice paid in awkward instalments still settles', function () {
    // Floating point makes 0.1 + 0.2 not equal 0.3. Compared to the penny, or
    // an invoice paid in three instalments sits at "part paid" forever with
    // nothing visibly outstanding.
    $invoice = issuedInvoice(0.30);

    pay($invoice, 0.10);
    pay($invoice, 0.20);

    expect($invoice->fresh()->paymentState())->toBe(PaymentState::Paid)
        ->and($invoice->fresh()->outstanding())->toBe(0.0);
});

test('an overpayment settles the invoice rather than going negative', function () {
    // Money over the total is a credit to sort out, not a negative balance on
    // this invoice.
    $invoice = issuedInvoice(1000);

    pay($invoice, 1200);

    $invoice = $invoice->fresh();

    expect($invoice->paymentState())->toBe(PaymentState::Paid)
        ->and($invoice->outstanding())->toBe(0.0)
        ->and((float) $invoice->amount_paid)->toBe(1200.0);
});

test('the stored sum always equals the payments on the invoice', function () {
    // The column is denormalised so outstanding balances can be queried in
    // SQL. This is what keeps it honest.
    $invoice = issuedInvoice(1000);

    pay($invoice, 100);
    pay($invoice, 250.55);
    pay($invoice, 49.45);

    $sum = round((float) Payment::query()->where('invoice_id', $invoice->id)->sum('amount'), 2);

    expect((float) $invoice->fresh()->amount_paid)->toBe($sum)
        ->and($sum)->toBe(400.0);
});

test('taking a payment back off recomputes the sum and clears the stamp', function () {
    // A payment recorded in error did not happen. A compensating negative row
    // would make the history read as two events when there were none.
    $invoice = issuedInvoice(1000);

    pay($invoice, 400);
    $mistake = pay($invoice, 600);

    expect($invoice->fresh()->paymentState())->toBe(PaymentState::Paid);

    app(RecordPaymentAction::class)->remove($mistake);

    $invoice = $invoice->fresh();

    expect((float) $invoice->amount_paid)->toBe(400.0)
        ->and($invoice->paymentState())->toBe(PaymentState::PartiallyPaid)
        ->and($invoice->paid_at)->toBeNull();
});

test('a payment has to be for something', function () {
    $invoice = issuedInvoice();

    expect(fn () => pay($invoice, 0))->toThrow(RuntimeException::class);
    expect(fn () => pay($invoice, -50))->toThrow(RuntimeException::class);

    expect(Payment::query()->count())->toBe(0);
});

test('a draft invoice cannot take a payment', function () {
    // Money arriving for a document the customer has never seen hides the real
    // question of what it was for.
    $invoice = Invoice::factory()->totalling(500)->create();

    expect(fn () => pay($invoice, 100))->toThrow(RuntimeException::class);
});

test('a cancelled invoice cannot take a payment', function () {
    $invoice = Invoice::factory()->create();
    $invoice = app(ChangeInvoiceStatusAction::class)($invoice, InvoiceStatus::Cancelled);

    expect(fn () => pay($invoice, 100))->toThrow(RuntimeException::class);
});

test('a payment records who said the money arrived, and when', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $invoice = issuedInvoice();
    $payment = app(RecordPaymentAction::class)($invoice, [
        'amount' => 250,
        'paid_on' => '2026-10-01',
        'method' => PaymentMethod::Cheque->value,
        'reference' => '000123',
    ]);

    expect($payment->recorded_by)->toBe($user->id)
        // The day the money arrived, not the day somebody typed it in.
        ->and($payment->paid_on->toDateString())->toBe('2026-10-01')
        ->and($payment->method())->toBe(PaymentMethod::Cheque)
        ->and($payment->reference)->toBe('000123');
});

// -- Overdue -------------------------------------------------------------------------

test('an issued invoice past its date with money owing is overdue', function () {
    Carbon::setTestNow('2026-10-15 09:00:00');

    $invoice = Invoice::factory()->totalling(1000)->dueOn('2026-10-20')->create();
    DocumentLine::factory()->on($invoice)->of(1, 1000)->create();
    $invoice = app(ChangeInvoiceStatusAction::class)($invoice, InvoiceStatus::Issued);

    expect($invoice->isOverdue())->toBeFalse();

    Carbon::setTestNow('2026-10-21 09:00:00');
    expect($invoice->fresh()->isOverdue())->toBeTrue()
        ->and(Invoice::query()->overdue()->count())->toBe(1);

    Carbon::setTestNow();
});

test('a settled invoice is never overdue, however long it took', function () {
    Carbon::setTestNow('2026-10-15 09:00:00');

    $invoice = Invoice::factory()->totalling(1000)->dueOn('2026-10-20')->create();
    DocumentLine::factory()->on($invoice)->of(1, 1000)->create();
    $invoice = app(ChangeInvoiceStatusAction::class)($invoice, InvoiceStatus::Issued);

    Carbon::setTestNow('2026-11-30 09:00:00');
    pay($invoice, 1000);

    expect($invoice->fresh()->isOverdue())->toBeFalse()
        ->and(Invoice::query()->overdue()->count())->toBe(0);

    Carbon::setTestNow();
});

test('a draft is never overdue', function () {
    // An invoice nobody sent cannot be late.
    Carbon::setTestNow('2026-12-01 09:00:00');

    $invoice = Invoice::factory()->dueOn('2026-10-01')->create();

    expect($invoice->isOverdue())->toBeFalse()
        ->and(Invoice::query()->overdue()->count())->toBe(0);

    Carbon::setTestNow();
});

test('the outstanding scope finds what is still owed', function () {
    $paid = issuedInvoice(100);
    pay($paid, 100);

    $partial = issuedInvoice(200);
    pay($partial, 50);

    $untouched = issuedInvoice(300);

    $outstanding = Invoice::query()->outstanding()->pluck('id');

    expect($outstanding)->toContain($partial->id)
        ->and($outstanding)->toContain($untouched->id)
        ->and($outstanding)->not->toContain($paid->id);
});

// -- Conversion from an order --------------------------------------------------------

test('an invoice raised from an order carries its lines and totals', function () {
    $user = User::factory()->create();
    $order = SalesOrder::factory()->ownedBy($user)->create();
    DocumentLine::factory()->on($order)->of(2, 250)->taxedAt(20)->create();
    app(SaveQuoteLinesAction::class)->writeTotals($order);

    $order = app(ChangeSalesOrderStatusAction::class)($order->fresh(), SalesOrderStatus::Confirmed);

    $invoice = app(ConvertOrderToInvoiceAction::class)($order);

    expect($invoice->sales_order_id)->toBe($order->id)
        ->and($invoice->lines)->toHaveCount(1)
        ->and((float) $invoice->total)->toBe((float) $order->total)
        ->and((float) $invoice->total)->toBe(600.0)
        ->and($invoice->status())->toBe(InvoiceStatus::Draft);
});

test('a draft order cannot be invoiced', function () {
    // Otherwise a customer receives a bill for an order they never placed.
    $order = SalesOrder::factory()->create();

    expect(fn () => app(ConvertOrderToInvoiceAction::class)($order))
        ->toThrow(RuntimeException::class);
});

test('an order can be invoiced more than once', function () {
    // Part invoicing an order is ordinary — unlike a quote, which becomes one
    // order and only one.
    $order = SalesOrder::factory()->withStatus(SalesOrderStatus::Confirmed)->create();
    DocumentLine::factory()->on($order)->of(1, 100)->create();

    $first = app(ConvertOrderToInvoiceAction::class)($order);
    $second = app(ConvertOrderToInvoiceAction::class)($order);

    expect($first->id)->not->toBe($second->id)
        ->and(Invoice::query()->where('sales_order_id', $order->id)->count())->toBe(2);
});

test('a whole quote to order to invoice chain keeps its figures', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $quote = app(CreateQuoteAction::class)([
        'bill_to_name' => 'Acme Ltd', 'owner_id' => $user->id,
    ]);
    app(SaveQuoteLinesAction::class)($quote, [
        ['name' => 'Consulting', 'quantity' => 3, 'unit_price' => 333.33, 'tax_rate' => 20],
    ]);
    app(ChangeQuoteStatusAction::class)($quote, QuoteStatus::Sent);
    $quote = app(ChangeQuoteStatusAction::class)($quote->fresh(), QuoteStatus::Accepted);

    $order = app(ConvertQuoteToOrderAction::class)($quote);
    $order = app(ChangeSalesOrderStatusAction::class)($order, SalesOrderStatus::Confirmed);
    $invoice = app(ConvertOrderToInvoiceAction::class)($order);

    // The same figure all the way through, untouched by three conversions.
    expect((float) $quote->total)->toBe(1199.99)
        ->and((float) $order->total)->toBe(1199.99)
        ->and((float) $invoice->total)->toBe(1199.99)
        ->and((float) $invoice->total)->toBe($invoice->totals()->total);
});

// -- Status -----------------------------------------------------------------------------

test('an invoice moves through the statuses it is allowed, and no others', function (string $from) {
    $source = InvoiceStatus::from($from);
    $allowed = $source->allowedTransitions();

    foreach ($allowed as $target) {
        $invoice = Invoice::factory()->create(['status' => $source->value]);
        DocumentLine::factory()->on($invoice)->of(1, 100)->create();

        expect(app(ChangeInvoiceStatusAction::class)($invoice, $target)->status())->toBe($target);
    }

    foreach (InvoiceStatus::cases() as $target) {
        if (in_array($target, $allowed, true) || $target === $source) {
            continue;
        }

        $invoice = Invoice::factory()->create(['status' => $source->value]);

        expect(fn () => app(ChangeInvoiceStatusAction::class)($invoice, $target))
            ->toThrow(RuntimeException::class);
    }
})->with(array_column(InvoiceStatus::cases(), 'value'));

test('an invoice with money against it cannot be cancelled', function () {
    // The money is real, and a cancelled invoice holding payments is a
    // reconciliation somebody unpicks by hand.
    $invoice = issuedInvoice(1000);
    pay($invoice, 100);

    expect(fn () => app(ChangeInvoiceStatusAction::class)($invoice->fresh(), InvoiceStatus::Cancelled))
        ->toThrow(RuntimeException::class);

    expect($invoice->fresh()->status())->toBe(InvoiceStatus::Issued);
});

test('an empty invoice cannot be issued', function () {
    $invoice = Invoice::factory()->create();

    expect(fn () => app(ChangeInvoiceStatusAction::class)($invoice, InvoiceStatus::Issued))
        ->toThrow(RuntimeException::class);
});

test('issuing stamps when it went out', function () {
    $invoice = Invoice::factory()->create();
    DocumentLine::factory()->on($invoice)->of(1, 100)->create();

    $invoice = app(ChangeInvoiceStatusAction::class)($invoice, InvoiceStatus::Issued);

    expect($invoice->issued_at)->not->toBeNull();
});

// -- The enums ----------------------------------------------------------------------------

test('the payment state follows the arithmetic', function (float $total, float $paid, PaymentState $expected) {
    expect(PaymentState::for($total, $paid))->toBe($expected);
})->with([
    'nothing paid' => [100.0, 0.0, PaymentState::Unpaid],
    'some paid' => [100.0, 40.0, PaymentState::PartiallyPaid],
    'all paid' => [100.0, 100.0, PaymentState::Paid],
    'overpaid' => [100.0, 150.0, PaymentState::Paid],
    'a penny short' => [100.0, 99.99, PaymentState::PartiallyPaid],
    'a penny over' => [100.0, 100.01, PaymentState::Paid],
    'nothing owed' => [0.0, 0.0, PaymentState::Paid],
]);

test('every invoice and payment enum reads as something', function () {
    foreach (InvoiceStatus::cases() as $status) {
        expect($status->label())->not->toBeEmpty()->and($status->color())->not->toBeEmpty();
    }

    foreach (PaymentState::cases() as $state) {
        expect($state->label())->not->toBeEmpty()->and($state->color())->not->toBeEmpty();
    }

    foreach (PaymentMethod::cases() as $method) {
        expect($method->label())->not->toBeEmpty();
    }
});

test('the invoice permissions are declared in the catalogue', function (string $permission) {
    expect(PermissionCatalogue::has($permission))->toBeTrue();
})->with(['invoices.view', 'invoices.create', 'invoices.update', 'invoices.issue', 'invoices.delete', 'invoices.export', 'invoices.payments']);
