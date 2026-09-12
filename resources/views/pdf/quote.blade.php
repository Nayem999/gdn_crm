{{-- A quote, as the customer receives it.

     Deliberately plain HTML with inline styles: dompdf supports a subset of
     CSS 2.1 and none of Tailwind's modern features, so the application's
     stylesheet would render as an unstyled page. Everything here is what dompdf
     can actually lay out. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $quote->reference() }}</title>
    <style>
        @page { margin: 30px 36px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #111827; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #6b7280; }
        .right { text-align: right; }
        .head td { vertical-align: top; padding-bottom: 18px; }
        .logo { max-height: 56px; }
        h1 { font-size: 20px; margin: 0 0 2px; }
        .meta td { padding: 1px 0; }
        .lines th { text-align: left; border-bottom: 1.5px solid #111827; padding: 6px 4px; font-size: 9.5px; text-transform: uppercase; letter-spacing: .04em; }
        .lines td { border-bottom: 1px solid #e5e7eb; padding: 6px 4px; vertical-align: top; }
        .totals { width: 240px; margin-left: auto; margin-top: 10px; }
        .totals td { padding: 3px 4px; }
        .totals .grand td { border-top: 1.5px solid #111827; font-weight: bold; font-size: 12px; padding-top: 6px; }
        .terms { margin-top: 26px; font-size: 9.5px; }
        .terms h2 { font-size: 10px; text-transform: uppercase; letter-spacing: .04em; margin: 0 0 4px; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td style="width: 55%;">
                @if ($logo)
                    <img src="{{ $logo }}" class="logo" alt="">
                @else
                    <strong style="font-size: 15px;">{{ $company->name }}</strong>
                @endif

                <div class="muted" style="margin-top: 6px;">
                    @if ($logo)<div>{{ $company->name }}</div>@endif
                    @foreach (array_filter([$company->address_line_1, $company->address_line_2, $company->city, $company->state, $company->postal_code, $company->country]) as $part)
                        <div>{{ $part }}</div>
                    @endforeach
                </div>
            </td>

            <td class="right">
                <h1>Quote</h1>
                <table class="meta right">
                    <tr><td class="muted">Reference</td><td><strong>{{ $quote->reference() }}</strong></td></tr>
                    <tr><td class="muted">Date</td><td>{{ $quote->issue_date->toFormattedDateString() }}</td></tr>
                    @if ($quote->valid_until)
                        <tr><td class="muted">Valid until</td><td>{{ $quote->valid_until->toFormattedDateString() }}</td></tr>
                    @endif
                    <tr><td class="muted">Prepared by</td><td>{{ $quote->owner?->name }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table style="margin-bottom: 16px;">
        <tr>
            <td style="width: 55%;">
                <div class="muted" style="font-size: 9.5px; text-transform: uppercase; letter-spacing: .04em;">Prepared for</div>
                <div style="margin-top: 3px;"><strong>{{ $quote->bill_to_name }}</strong></div>
                @if ($quote->bill_to_address)
                    <div class="muted" style="white-space: pre-line;">{{ $quote->bill_to_address }}</div>
                @endif
            </td>
        </tr>
    </table>

    @if ($quote->intro)
        <p style="margin: 0 0 14px; white-space: pre-line;">{{ $quote->intro }}</p>
    @endif

    <table class="lines">
        <thead>
            <tr>
                <th style="width: 44%;">Item</th>
                <th style="width: 14%;">Quantity</th>
                <th class="right" style="width: 14%;">Unit price</th>
                <th class="right" style="width: 12%;">Discount</th>
                <th class="right" style="width: 16%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($quote->lines as $line)
                <tr>
                    <td>
                        <strong>{{ $line->name }}</strong>
                        @if ($line->description)
                            <div class="muted" style="white-space: pre-line;">{{ $line->description }}</div>
                        @endif
                    </td>
                    <td>{{ $line->quantityLabel() }}</td>
                    <td class="right">{{ number_format((float) $line->unit_price, 2) }}</td>
                    <td class="right">{{ $line->discountLabel() ?? '—' }}</td>
                    {{-- The stored figure, not a recomputation: this is what the
                         screen showed when somebody pressed send. --}}
                    <td class="right">{{ number_format((float) $line->net_total, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">No items.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="muted">Subtotal</td>
            <td class="right">{{ number_format($totals->net, 2) }}</td>
        </tr>

        @if ($totals->hasDiscount())
            <tr>
                <td class="muted">Discount</td>
                <td class="right">&minus;{{ number_format($totals->discount, 2) }}</td>
            </tr>
        @endif

        {{-- Each band separately when there is more than one: a tax authority
             asks for the twenty-per-cent figure, not the total. --}}
        @foreach ($totals->taxByRate as $rate => $amount)
            <tr>
                <td class="muted">Tax at {{ rtrim(rtrim($rate, '0'), '.') }}%</td>
                <td class="right">{{ number_format($amount, 2) }}</td>
            </tr>
        @endforeach

        <tr class="grand">
            <td>Total {{ $currency }}</td>
            <td class="right">{{ number_format($totals->total, 2) }}</td>
        </tr>

        @if ($quote->taxMode()->includesTax())
            <tr><td colspan="2" class="muted" style="font-size: 9px;">Prices include tax.</td></tr>
        @endif
    </table>

    @if ($quote->terms)
        <div class="terms">
            <h2>Terms</h2>
            <div style="white-space: pre-line;">{{ $quote->terms }}</div>
        </div>
    @endif
</body>
</html>
