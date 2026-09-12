<?php

use App\Domain\Access\PermissionResolver;
use App\Domain\Accounts\Models\Account;
use App\Domain\Products\Models\PriceBook;
use App\Domain\Products\Models\PriceBookEntry;
use App\Domain\Products\Models\Product;
use App\Domain\Sales\Actions\ChangeQuoteStatusAction;
use App\Domain\Sales\Actions\CreateQuoteAction;
use App\Domain\Sales\Actions\ExpireQuotesAction;
use App\Domain\Sales\Actions\ReviseQuoteAction;
use App\Domain\Sales\Actions\SaveQuoteLinesAction;
use App\Domain\Sales\Actions\SendQuoteAction;
use App\Domain\Sales\Documents\DocumentNumber;
use App\Domain\Sales\Documents\QuotePdf;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Enums\TaxMode;
use App\Domain\Sales\Models\DocumentLine;
use App\Domain\Sales\Models\Quote;
use App\Livewire\Sales\QuoteBuilder;
use App\Livewire\Sales\QuotesIndex;
use App\Mail\QuoteMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function quoteUser(array $permissions = ['quotes.view', 'quotes.create', 'quotes.update', 'quotes.send', 'quotes.delete']): User
{
    $user = User::factory()->create();

    foreach (PermissionResolver::models($permissions) as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

/**
 * A saved quote with lines on it, through the real actions.
 *
 * @param  array<int, array<string, mixed>>  $lines
 */
function quoteWithLines(array $lines = [], array $attributes = []): Quote
{
    $quote = app(CreateQuoteAction::class)([
        'bill_to_name' => 'Acme Ltd',
        'bill_to_email' => 'buyer@example.com',
        'owner_id' => auth()->id() ?? User::factory()->create()->id,
        ...$attributes,
    ]);

    app(SaveQuoteLinesAction::class)($quote, $lines === [] ? [
        ['name' => 'Consulting', 'quantity' => 2, 'unit_price' => 500, 'tax_rate' => 20],
    ] : $lines);

    return $quote->fresh();
}

beforeEach(function () {
    Mail::fake();
});

// -- Totals match ------------------------------------------------------------

test('a quote stores the totals its lines come to', function () {
    $this->actingAs(quoteUser());

    $quote = quoteWithLines([
        ['name' => 'Consulting', 'quantity' => 2, 'unit_price' => 500, 'tax_rate' => 20],
        ['name' => 'Licence', 'quantity' => 1, 'unit_price' => 200, 'tax_rate' => 20, 'discount_type' => DiscountType::Percentage->value, 'discount_value' => 10],
    ]);

    // 1000 + 180 net, 200 + 36 tax.
    expect((float) $quote->subtotal)->toBe(1180.0)
        ->and((float) $quote->discount_total)->toBe(20.0)
        ->and((float) $quote->tax_total)->toBe(236.0)
        ->and((float) $quote->total)->toBe(1416.0);
});

test('the stored header always equals the sum of the stored lines', function () {
    // The header and the lines are written by the same action, in the same
    // transaction, from the same calculator. If they can diverge, a quote can
    // print a total that is not the sum of its own rows.
    $this->actingAs(quoteUser());

    $quote = quoteWithLines([
        ['name' => 'A', 'quantity' => 3, 'unit_price' => 33.33, 'tax_rate' => 20],
        ['name' => 'B', 'quantity' => 7, 'unit_price' => 0.125, 'tax_rate' => 5],
        ['name' => 'C', 'quantity' => 1.5, 'unit_price' => 99.99, 'discount_type' => DiscountType::Amount->value, 'discount_value' => 10],
    ]);

    $lineSum = $quote->lines->sum(fn (DocumentLine $line): float => (float) $line->line_total);

    expect((float) $quote->total)->toBe(round($lineSum, 2))
        ->and((float) $quote->total)->toBe($quote->totals()->total);
});

test('a quote prices from the book it was built in', function () {
    $this->actingAs(quoteUser());

    $product = Product::factory()->pricedAt(100)->create();
    $book = PriceBook::factory()->create();
    PriceBookEntry::factory()->for_($book, $product, 75)->create();

    $quote = quoteWithLines(
        [['product_id' => $product->id, 'quantity' => 2]],
        ['price_book_id' => $book->id],
    );

    // The book's price, not the catalogue's.
    expect((float) $quote->lines->first()->unit_price)->toBe(75.0)
        ->and((float) $quote->total)->toBe(150.0);
});

test('a price typed on the line beats the book', function () {
    // Quoting means being able to agree a figure.
    $this->actingAs(quoteUser());

    $product = Product::factory()->pricedAt(100)->create();

    $quote = quoteWithLines([['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 60]]);

    expect((float) $quote->lines->first()->unit_price)->toBe(60.0);
});

test('a tax-inclusive quote totals from the other end', function () {
    $this->actingAs(quoteUser());

    $quote = quoteWithLines(
        [['name' => 'Inclusive', 'quantity' => 1, 'unit_price' => 120, 'tax_rate' => 20]],
        ['tax_mode' => TaxMode::Inclusive->value],
    );

    expect((float) $quote->subtotal)->toBe(100.0)
        ->and((float) $quote->tax_total)->toBe(20.0)
        ->and((float) $quote->total)->toBe(120.0);
});

test('saving again reconciles the lines rather than piling them up', function () {
    $this->actingAs(quoteUser());

    $quote = quoteWithLines([
        ['name' => 'Keep', 'quantity' => 1, 'unit_price' => 100],
        ['name' => 'Drop', 'quantity' => 1, 'unit_price' => 50],
    ]);

    $keepId = $quote->lines->first()->id;

    app(SaveQuoteLinesAction::class)($quote, [
        ['id' => $keepId, 'name' => 'Keep', 'quantity' => 3, 'unit_price' => 100],
        ['name' => 'New', 'quantity' => 1, 'unit_price' => 25],
    ]);

    $quote = $quote->fresh();

    expect($quote->lines)->toHaveCount(2)
        // The kept line holds its id, so 6.5 can trace an invoice line back.
        ->and($quote->lines->first()->id)->toBe($keepId)
        ->and((float) $quote->total)->toBe(325.0);
});

test('a line id from another quote cannot be pulled onto this one', function () {
    $this->actingAs(quoteUser());

    $mine = quoteWithLines();
    $theirs = quoteWithLines();
    $theirLine = $theirs->lines->first();

    app(SaveQuoteLinesAction::class)($mine, [
        ['id' => $theirLine->id, 'name' => 'Stolen', 'quantity' => 1, 'unit_price' => 1],
    ]);

    expect($theirLine->fresh()->document_id)->toBe($theirs->id)
        ->and($theirLine->fresh()->name)->not->toBe('Stolen');
});

test('a row with nothing on it is dropped rather than saved', function () {
    $this->actingAs(quoteUser());

    $quote = quoteWithLines([
        ['name' => 'Real', 'quantity' => 1, 'unit_price' => 100],
        ['name' => '', 'quantity' => 0, 'unit_price' => ''],
    ]);

    expect($quote->lines)->toHaveCount(1);
});

// -- Status transitions are valid ------------------------------------------------

test('a quote moves through the statuses it is allowed', function () {
    $this->actingAs(quoteUser());

    $quote = quoteWithLines();

    expect($quote->status())->toBe(QuoteStatus::Draft);

    $quote = app(ChangeQuoteStatusAction::class)($quote, QuoteStatus::Sent);
    expect($quote->status())->toBe(QuoteStatus::Sent)
        ->and($quote->sent_at)->not->toBeNull();

    $quote = app(ChangeQuoteStatusAction::class)($quote, QuoteStatus::Accepted);
    expect($quote->status())->toBe(QuoteStatus::Accepted)
        ->and($quote->accepted_at)->not->toBeNull();
});

test('an accepted quote cannot be rewritten', function () {
    // Otherwise it is a form somebody can change after the customer agreed to
    // it, and 6.4 builds orders from these.
    $this->actingAs(quoteUser());

    $quote = quoteWithLines();
    app(ChangeQuoteStatusAction::class)($quote, QuoteStatus::Sent);
    $quote = app(ChangeQuoteStatusAction::class)($quote->fresh(), QuoteStatus::Accepted);

    expect(fn () => app(ChangeQuoteStatusAction::class)($quote, QuoteStatus::Draft))
        ->toThrow(RuntimeException::class);

    expect(fn () => app(ChangeQuoteStatusAction::class)($quote, QuoteStatus::Sent))
        ->toThrow(RuntimeException::class);
});

test('a draft cannot skip straight to accepted', function () {
    $this->actingAs(quoteUser());

    $quote = quoteWithLines();

    expect(fn () => app(ChangeQuoteStatusAction::class)($quote, QuoteStatus::Accepted))
        ->toThrow(RuntimeException::class);
});

test('every transition the enum allows is one the action performs, and no others', function (string $from) {
    $this->actingAs(quoteUser());

    $source = QuoteStatus::from($from);
    $allowed = $source->allowedTransitions();

    foreach ($allowed as $target) {
        $quote = Quote::factory()->create(['status' => $source->value]);

        expect(app(ChangeQuoteStatusAction::class)($quote, $target)->status())->toBe($target);
    }

    // The other half, and the only thing this says at all for a terminal
    // status: everything not listed is refused. Without it the accepted and
    // superseded cases assert nothing and pass for it.
    foreach (QuoteStatus::cases() as $target) {
        if (in_array($target, $allowed, true) || $target === $source) {
            continue;
        }

        $quote = Quote::factory()->create(['status' => $source->value]);

        expect(fn () => app(ChangeQuoteStatusAction::class)($quote, $target))
            ->toThrow(RuntimeException::class);
    }
})->with(array_column(QuoteStatus::cases(), 'value'));

test('a refused move says which move it refused', function () {
    $this->actingAs(quoteUser());

    $quote = Quote::factory()->create(['status' => QuoteStatus::Expired->value]);

    try {
        app(ChangeQuoteStatusAction::class)($quote, QuoteStatus::Accepted);
        $this->fail('The move should have been refused.');
    } catch (RuntimeException $refused) {
        expect($refused->getMessage())->toContain('expired')
            ->and($refused->getMessage())->toContain('accepted');
    }
});

test('a sent quote lapses when its date passes', function () {
    Carbon::setTestNow('2026-10-15 09:00:00');
    $this->actingAs(quoteUser());

    $quote = quoteWithLines([], ['valid_until' => '2026-10-20']);
    app(ChangeQuoteStatusAction::class)($quote, QuoteStatus::Sent);

    Carbon::setTestNow('2026-10-20 23:00:00');
    expect(app(ExpireQuotesAction::class)())->toBe(0);

    Carbon::setTestNow('2026-10-21 00:30:00');
    expect(app(ExpireQuotesAction::class)())->toBe(1)
        ->and($quote->fresh()->status())->toBe(QuoteStatus::Expired);

    Carbon::setTestNow();
});

test('a draft does not lapse, and neither does an accepted quote', function () {
    Carbon::setTestNow('2026-10-15 09:00:00');
    $this->actingAs(quoteUser());

    $draft = quoteWithLines([], ['valid_until' => '2026-10-01']);
    $accepted = quoteWithLines([], ['valid_until' => '2026-10-01']);
    app(ChangeQuoteStatusAction::class)($accepted, QuoteStatus::Sent);
    app(ChangeQuoteStatusAction::class)($accepted->fresh(), QuoteStatus::Accepted);

    expect(app(ExpireQuotesAction::class)())->toBe(0)
        ->and($draft->fresh()->status())->toBe(QuoteStatus::Draft)
        ->and($accepted->fresh()->status())->toBe(QuoteStatus::Accepted);

    Carbon::setTestNow();
});

// -- Versioning -------------------------------------------------------------------

test('revising a quote leaves the sent one exactly as it was', function () {
    // The customer holds a copy. The one thing a disputed quote must not be is
    // a reconstruction.
    $this->actingAs(quoteUser());

    $original = quoteWithLines([['name' => 'As sent', 'quantity' => 1, 'unit_price' => 100]]);
    app(ChangeQuoteStatusAction::class)($original, QuoteStatus::Sent);

    $next = app(ReviseQuoteAction::class)($original->fresh());
    $original = $original->fresh();

    expect($original->status())->toBe(QuoteStatus::Superseded)
        ->and($original->superseded_at)->not->toBeNull()
        ->and($original->lines->first()->name)->toBe('As sent')
        ->and((float) $original->total)->toBe(100.0);

    expect($next->version)->toBe(2)
        ->and($next->number)->toBe($original->number)
        ->and($next->status())->toBe(QuoteStatus::Draft)
        ->and($next->rootId())->toBe($original->id)
        // The lines came across.
        ->and($next->lines)->toHaveCount(1)
        ->and($next->lines->first()->name)->toBe('As sent');
});

test('a version reads as the number with its version', function () {
    $this->actingAs(quoteUser());

    $original = quoteWithLines();
    app(ChangeQuoteStatusAction::class)($original, QuoteStatus::Sent);
    $next = app(ReviseQuoteAction::class)($original->fresh());

    expect($original->fresh()->reference())->toBe($original->number)
        ->and($next->reference())->toBe($original->number.' v2');
});

test('revising twice from the same version does not make two v2s', function () {
    $this->actingAs(quoteUser());

    $v1 = quoteWithLines();
    app(ChangeQuoteStatusAction::class)($v1, QuoteStatus::Sent);
    $v2 = app(ReviseQuoteAction::class)($v1->fresh());

    app(ChangeQuoteStatusAction::class)($v2, QuoteStatus::Sent);
    $v3 = app(ReviseQuoteAction::class)($v2->fresh());

    expect($v3->version)->toBe(3)
        ->and(Quote::query()->where('number', $v1->number)->count())->toBe(3);
});

test('a draft cannot be revised — it is already editable', function () {
    $this->actingAs(quoteUser());

    $quote = quoteWithLines();

    expect(fn () => app(ReviseQuoteAction::class)($quote))->toThrow(RuntimeException::class);
});

test('an accepted quote cannot be revised', function () {
    $this->actingAs(quoteUser());

    $quote = Quote::factory()->create(['status' => QuoteStatus::Accepted->value]);

    expect(fn () => app(ReviseQuoteAction::class)($quote))->toThrow(RuntimeException::class);
});

test('the list shows current versions and hides the superseded ones', function () {
    $user = quoteUser();
    $this->actingAs($user);

    $v1 = quoteWithLines([], ['owner_id' => $user->id]);
    app(ChangeQuoteStatusAction::class)($v1, QuoteStatus::Sent);
    app(ReviseQuoteAction::class)($v1->fresh());

    $screen = Livewire::actingAs($user)->test(QuotesIndex::class);

    expect($screen->instance()->dataViewBaseQuery()->count())->toBe(1);

    $screen->set('includeSuperseded', true);

    expect($screen->instance()->dataViewBaseQuery()->count())->toBe(2);
});

// -- Numbering ---------------------------------------------------------------------

test('quote numbers are sequential and never repeat', function () {
    $this->actingAs(quoteUser());

    $numbers = [];

    for ($i = 0; $i < 5; $i++) {
        $numbers[] = app(CreateQuoteAction::class)(['bill_to_name' => 'Acme', 'owner_id' => auth()->id()])->number;
    }

    expect($numbers)->toHaveCount(5)
        ->and(array_unique($numbers))->toHaveCount(5);

    // Sequential within the year, which is how everybody reads them.
    expect($numbers[0])->toMatch('/^Q-\d{4}-0001$/')
        ->and($numbers[4])->toMatch('/^Q-\d{4}-0005$/');
});

test('the counter is per year', function () {
    expect(DocumentNumber::next('probe', 'X', 2026))->toBe('X-2026-0001')
        ->and(DocumentNumber::next('probe', 'X', 2026))->toBe('X-2026-0002')
        // A new year starts again, because a counter that never resets prints
        // a number nobody can say aloud.
        ->and(DocumentNumber::next('probe', 'X', 2027))->toBe('X-2027-0001');
});

// -- Sending ------------------------------------------------------------------------

test('sending queues the quote with its pdf and marks it sent', function () {
    $this->actingAs(quoteUser());

    $quote = quoteWithLines();

    app(SendQuoteAction::class)($quote, 'Your quote', 'As discussed.');

    expect($quote->fresh()->status())->toBe(QuoteStatus::Sent)
        ->and($quote->fresh()->sent_at)->not->toBeNull();

    Mail::assertQueued(QuoteMail::class, function (QuoteMail $mail) use ($quote): bool {
        return $mail->hasTo('buyer@example.com')
            && $mail->subject === 'Your quote'
            && $mail->quote->is($quote);
    });
});

test('a quote with nowhere to send is refused, and not marked sent', function () {
    // A quote marked sent that was never sent is the worse of the two
    // failures, because nobody looks for it again.
    $this->actingAs(quoteUser());

    $quote = quoteWithLines([], ['bill_to_email' => '']);

    expect(fn () => app(SendQuoteAction::class)($quote))->toThrow(RuntimeException::class);

    expect($quote->fresh()->status())->toBe(QuoteStatus::Draft);
    Mail::assertNothingQueued();
});

test('an empty quote cannot be sent', function () {
    $this->actingAs(quoteUser());

    $quote = app(CreateQuoteAction::class)([
        'bill_to_name' => 'Acme',
        'bill_to_email' => 'buyer@example.com',
        'owner_id' => auth()->id(),
    ]);

    expect(fn () => app(SendQuoteAction::class)($quote))->toThrow(RuntimeException::class);
});

test('only a draft can be sent', function () {
    $this->actingAs(quoteUser());

    $quote = quoteWithLines();
    app(ChangeQuoteStatusAction::class)($quote, QuoteStatus::Sent);

    expect(fn () => app(SendQuoteAction::class)($quote->fresh()))->toThrow(RuntimeException::class);
});

// -- The PDF --------------------------------------------------------------------------

test('the pdf generates and is a pdf', function () {
    $this->actingAs(quoteUser());

    $quote = quoteWithLines();
    $bytes = app(QuotePdf::class)->render($quote);

    expect($bytes)->toStartWith('%PDF-')
        ->and(strlen($bytes))->toBeGreaterThan(1000);
});

test('the pdf is named after the quote reference', function () {
    $this->actingAs(quoteUser());

    $quote = quoteWithLines();
    app(ChangeQuoteStatusAction::class)($quote, QuoteStatus::Sent);
    $next = app(ReviseQuoteAction::class)($quote->fresh());

    expect(app(QuotePdf::class)->filename($next))->toBe($next->number.'-v2.pdf');
});

test('the pdf route is behind the quote policy', function () {
    $owner = quoteUser();
    $this->actingAs($owner);
    $quote = quoteWithLines([], ['owner_id' => $owner->id]);

    // A PDF holds prices, and margins are inferable from them. "It is only a
    // PDF" is how an access level gets forgotten.
    $this->actingAs(User::factory()->create())
        ->get(route('quotes.pdf', $quote))
        ->assertForbidden();

    $this->actingAs($owner)
        ->get(route('quotes.pdf', $quote))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

// -- The screens -----------------------------------------------------------------------

test('the builder saves a quote with its lines', function () {
    $user = quoteUser();
    $product = Product::factory()->pricedAt(250)->ownedBy($user)->create(['name' => 'Consulting day']);

    Livewire::actingAs($user)
        ->test(QuoteBuilder::class)
        ->set('billToName', 'Acme Ltd')
        ->set('billToEmail', 'buyer@example.com')
        ->set('ownerId', (string) $user->id)
        ->set('lines', [
            ['id' => null, 'product_id' => (string) $product->id, 'name' => 'Consulting day', 'description' => '',
                'quantity' => '4', 'unit_price' => '250', 'discount_type' => '', 'discount_value' => '', 'tax_rate' => '20'],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $quote = Quote::query()->sole();

    expect($quote->bill_to_name)->toBe('Acme Ltd')
        ->and($quote->lines)->toHaveCount(1)
        ->and((float) $quote->total)->toBe(1200.0);
});

test('the builder refuses a quote that expires before it is issued', function () {
    $user = quoteUser();

    Livewire::actingAs($user)
        ->test(QuoteBuilder::class)
        ->set('billToName', 'Acme Ltd')
        ->set('ownerId', (string) $user->id)
        ->set('issueDate', '2026-10-20')
        ->set('validUntil', '2026-10-01')
        ->call('save')
        ->assertHasErrors('validUntil');
});

test('choosing a product fills the line from the price book', function () {
    $user = quoteUser();
    $product = Product::factory()->pricedAt(100)->ownedBy($user)->create(['name' => 'Widget']);
    $book = PriceBook::factory()->create();
    PriceBookEntry::factory()->for_($book, $product, 75)->create();

    $screen = Livewire::actingAs($user)
        ->test(QuoteBuilder::class)
        ->set('priceBookId', (string) $book->id)
        ->set('lines.0.product_id', (string) $product->id);

    expect($screen->get('lines')[0]['name'])->toBe('Widget')
        ->and($screen->get('lines')[0]['unit_price'])->toBe('75');
});

test('a sent quote is not editable through the builder', function () {
    $user = quoteUser();
    $this->actingAs($user);

    $quote = quoteWithLines([], ['owner_id' => $user->id]);
    app(ChangeQuoteStatusAction::class)($quote, QuoteStatus::Sent);

    $screen = Livewire::actingAs($user)->test(QuoteBuilder::class, ['quote' => $quote->fresh()]);

    expect($screen->instance()->isEditable())->toBeFalse();

    $screen->set('billToName', 'Changed')->call('save')->assertForbidden();

    expect($quote->fresh()->bill_to_name)->toBe('Acme Ltd');
});

test('the quotes screens need their permissions', function () {
    $this->actingAs(User::factory()->create())->get(route('quotes.index'))->assertForbidden();
    $this->actingAs(quoteUser(['quotes.view']))->get(route('quotes.index'))->assertOk();
    $this->actingAs(quoteUser(['quotes.view']))->get(route('quotes.create'))->assertForbidden();
    $this->actingAs(quoteUser(['quotes.view', 'quotes.create']))->get(route('quotes.create'))->assertOk();
});

test('a quote copies the billing details rather than linking to them', function () {
    // Renaming an account must not rewrite a document the customer holds.
    $this->actingAs(quoteUser());

    $account = Account::factory()->create(['name' => 'Acme Ltd', 'city' => 'Dhaka']);

    $quote = app(CreateQuoteAction::class)([
        'account_id' => $account->id,
        'owner_id' => auth()->id(),
    ]);

    expect($quote->bill_to_name)->toBe('Acme Ltd')
        ->and($quote->bill_to_address)->toContain('Dhaka');

    $account->forceFill(['name' => 'Acme Holdings'])->save();

    expect($quote->fresh()->bill_to_name)->toBe('Acme Ltd');
});

test('every quote status reads as something and has a colour', function (string $value) {
    $status = QuoteStatus::from($value);

    expect($status->label())->not->toBeEmpty()
        ->and($status->color())->not->toBeEmpty();
})->with(array_column(QuoteStatus::cases(), 'value'));
