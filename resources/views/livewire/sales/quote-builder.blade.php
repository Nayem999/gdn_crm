@php
    use App\Domain\Sales\Enums\QuoteStatus;
    use App\Domain\Settings\NumberFormat;

    $quote = $this->quote();
    $editable = $this->isEditable();
    $totals = $this->liveTotals();
@endphp

<div class="mx-auto max-w-5xl space-y-6 pb-16">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-foreground">
                {{ $quote?->reference() ?? 'New quote' }}
            </h1>
            <p class="mt-1 flex items-center gap-2 text-sm text-muted-foreground">
                @if ($quote)
                    <x-status-chip :color="$quote->status()->color()" dot>{{ $quote->status()->label() }}</x-status-chip>
                    @unless ($editable)
                        <span>Sent quotes cannot be edited — raise a new version instead.</span>
                    @endunless
                @else
                    <span>Build it from the catalogue, then send it.</span>
                @endif
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($quote)
                <a href="{{ route('quotes.pdf', $quote) }}" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted">
                    <x-icon name="lucide-file-down" />
                    PDF
                </a>
            @endif

            <a href="{{ route('quotes.index') }}" wire:navigate
               class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted">
                <x-icon name="lucide-arrow-left" />
                All quotes
            </a>
        </div>
    </div>

    @if ($error)
        <x-alert variant="error">{{ $error }}</x-alert>
    @endif

    @if ($saved)
        <x-alert variant="success">{{ $saved }}</x-alert>
    @endif

    <form wire:submit="save" class="space-y-6">
        <section class="rounded-xl border border-border bg-card p-5">
            <h2 class="text-sm font-semibold text-foreground">Who it is for</h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div wire:key="account-{{ $accountId }}">
                    <x-form.label for="accountId">Account</x-form.label>
                    <x-select name="accountId" :options="$this->accountOptions()" :selected="$accountId"
                              placeholder="No account" wire:model.live="accountId" :disabled="! $editable" />
                    <x-form.error for="accountId" />
                </div>

                <div wire:key="contact-{{ $accountId }}-{{ $contactId }}">
                    <x-form.label for="contactId">Contact</x-form.label>
                    <x-select name="contactId" :options="$this->contactOptions()" :selected="$contactId"
                              placeholder="No contact" wire:model.live="contactId" :disabled="! $editable" />
                    <x-form.error for="contactId" />
                </div>

                <div>
                    <x-form.label for="billToName" required>Addressed to</x-form.label>
                    <x-form.input id="billToName" wire:model="billToName" :invalid="$errors->has('billToName')" :disabled="! $editable" />
                    <x-form.error for="billToName" />
                </div>

                <div>
                    <x-form.label for="billToEmail">Email</x-form.label>
                    <x-form.input id="billToEmail" type="email" wire:model="billToEmail" :invalid="$errors->has('billToEmail')" :disabled="! $editable" />
                    <x-form.error for="billToEmail" />
                </div>

                <div class="sm:col-span-2">
                    <x-form.label for="billToAddress">Address</x-form.label>
                    <textarea id="billToAddress" rows="3" wire:model="billToAddress" @disabled(! $editable)
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"></textarea>
                    <x-form.error for="billToAddress" />
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-border bg-card p-5">
            <h2 class="text-sm font-semibold text-foreground">Terms of the offer</h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-4">
                <div>
                    <x-form.label for="issueDate" required>Issued</x-form.label>
                    <x-form.input id="issueDate" type="date" wire:model="issueDate" :invalid="$errors->has('issueDate')" :disabled="! $editable" />
                    <x-form.error for="issueDate" />
                </div>

                <div>
                    <x-form.label for="validUntil">Valid until</x-form.label>
                    <x-form.input id="validUntil" type="date" wire:model="validUntil" :invalid="$errors->has('validUntil')" :disabled="! $editable" />
                    <x-form.error for="validUntil" />
                </div>

                <div wire:key="taxmode-{{ $taxMode }}">
                    <x-form.label for="taxMode" required>Tax</x-form.label>
                    <x-select name="taxMode" :options="$this->taxModeOptions()" :selected="$taxMode" wire:model.live="taxMode" :disabled="! $editable" />
                    <x-form.error for="taxMode" />
                </div>

                <div wire:key="pricebook-{{ $priceBookId }}">
                    <x-form.label for="priceBookId">Priced from</x-form.label>
                    <x-select name="priceBookId" :options="$this->priceBookOptions()" :selected="$priceBookId" wire:model.live="priceBookId" :disabled="! $editable" />
                    <x-form.error for="priceBookId" />
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-border bg-card p-5">
            <h2 class="text-sm font-semibold text-foreground">What is being quoted</h2>

            <div class="mt-4 space-y-3">
                @foreach ($lines as $index => $line)
                    <div class="rounded-lg border border-border p-3" wire:key="line-{{ $index }}">
                        <div class="grid gap-3 sm:grid-cols-12">
                            <div class="sm:col-span-4" wire:key="line-{{ $index }}-product-{{ $line['product_id'] ?? '' }}">
                                <x-form.label :for="'lines.'.$index.'.product_id'">Product</x-form.label>
                                <x-select
                                    :name="'lines.'.$index.'.product_id'"
                                    :options="$this->productOptions()"
                                    :selected="$line['product_id'] ?? ''"
                                    placeholder="Free text line"
                                    wire:model.live="lines.{{ $index }}.product_id"
                                    :disabled="! $editable"
                                />
                            </div>

                            <div class="sm:col-span-4">
                                <x-form.label :for="'lines.'.$index.'.name'">Description</x-form.label>
                                <x-form.input :id="'lines.'.$index.'.name'" wire:model="lines.{{ $index }}.name" :disabled="! $editable" />
                            </div>

                            <div class="sm:col-span-1">
                                <x-form.label :for="'lines.'.$index.'.quantity'">Qty</x-form.label>
                                <x-form.input :id="'lines.'.$index.'.quantity'" type="number" step="0.001" min="0"
                                              wire:model.live.debounce.400ms="lines.{{ $index }}.quantity" :disabled="! $editable" />
                            </div>

                            <div class="sm:col-span-2">
                                <x-form.label :for="'lines.'.$index.'.unit_price'">Unit price</x-form.label>
                                <x-form.input :id="'lines.'.$index.'.unit_price'" type="number" step="0.01" min="0"
                                              wire:model.live.debounce.400ms="lines.{{ $index }}.unit_price" :disabled="! $editable" />
                            </div>

                            <div class="flex items-end sm:col-span-1">
                                @if ($editable)
                                    <div class="flex items-center gap-1 pb-1">
                                        <button type="button" class="rounded p-1 text-muted-foreground hover:bg-muted disabled:opacity-30"
                                                wire:click="moveLine({{ $index }}, -1)" @disabled($index === 0) aria-label="Move up">
                                            <x-icon name="lucide-chevron-up" class="h-4 w-4" />
                                        </button>
                                        <button type="button" class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-destructive"
                                                wire:click="removeLine({{ $index }})" aria-label="Remove this line">
                                            <x-icon name="lucide-trash-2" class="h-4 w-4" />
                                        </button>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="mt-2 grid gap-3 sm:grid-cols-12">
                            <div class="sm:col-span-3" wire:key="line-{{ $index }}-dtype-{{ $line['discount_type'] ?? '' }}">
                                <x-form.label :for="'lines.'.$index.'.discount_type'">Discount</x-form.label>
                                <x-select
                                    :name="'lines.'.$index.'.discount_type'"
                                    :options="$this->discountTypeOptions()"
                                    :selected="$line['discount_type'] ?? ''"
                                    wire:model.live="lines.{{ $index }}.discount_type"
                                    :disabled="! $editable"
                                />
                            </div>

                            <div class="sm:col-span-2">
                                <x-form.label :for="'lines.'.$index.'.discount_value'">Amount</x-form.label>
                                <x-form.input :id="'lines.'.$index.'.discount_value'" type="number" step="0.01" min="0"
                                              wire:model.live.debounce.400ms="lines.{{ $index }}.discount_value" :disabled="! $editable" />
                            </div>

                            <div class="sm:col-span-2">
                                <x-form.label :for="'lines.'.$index.'.tax_rate'">Tax %</x-form.label>
                                <x-form.input :id="'lines.'.$index.'.tax_rate'" type="number" step="0.01" min="0" max="100"
                                              wire:model.live.debounce.400ms="lines.{{ $index }}.tax_rate" :disabled="! $editable" />
                            </div>

                            <div class="sm:col-span-5">
                                <x-form.label :for="'lines.'.$index.'.description'">Notes on this line</x-form.label>
                                <x-form.input :id="'lines.'.$index.'.description'" wire:model="lines.{{ $index }}.description" :disabled="! $editable" />
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($editable)
                <button type="button" class="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-accent hover:underline" wire:click="addLine">
                    <x-icon name="lucide-plus" />
                    Add a line
                </button>
            @endif

            {{-- The totals as stored, from the same calculator the PDF reads,
                 so the screen and the document cannot disagree. --}}
            <div class="mt-5 ml-auto w-full max-w-xs space-y-1 border-t border-border pt-3 text-sm">
                <div class="flex justify-between"><span class="text-muted-foreground">Subtotal</span><span class="tabular-nums">{{ NumberFormat::format($totals->net, 2) }}</span></div>

                @if ($totals->hasDiscount())
                    <div class="flex justify-between"><span class="text-muted-foreground">Discount</span><span class="tabular-nums">&minus;{{ NumberFormat::format($totals->discount, 2) }}</span></div>
                @endif

                @foreach ($totals->taxByRate as $rate => $amount)
                    <div class="flex justify-between"><span class="text-muted-foreground">Tax at {{ rtrim(rtrim($rate, '0'), '.') }}%</span><span class="tabular-nums">{{ NumberFormat::format($amount, 2) }}</span></div>
                @endforeach

                <div class="flex justify-between border-t border-border pt-1 text-base font-semibold">
                    <span>Total</span><span class="tabular-nums">{{ NumberFormat::format($totals->total, 2) }}</span>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-border bg-card p-5">
            <h2 class="text-sm font-semibold text-foreground">What it says</h2>

            <div class="mt-4 space-y-4">
                <div>
                    <x-form.label for="intro">Introduction</x-form.label>
                    <textarea id="intro" rows="2" wire:model="intro" @disabled(! $editable)
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"></textarea>
                </div>

                <div>
                    <x-form.label for="terms">Terms</x-form.label>
                    <textarea id="terms" rows="3" wire:model="terms" @disabled(! $editable)
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"></textarea>
                </div>

                <div>
                    <x-form.label for="notes">Internal notes</x-form.label>
                    <textarea id="notes" rows="2" wire:model="notes" @disabled(! $editable)
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"></textarea>
                    <p class="mt-1 text-xs text-muted-foreground">Never printed and never sent.</p>
                </div>
            </div>
        </section>

        @if ($editable)
            <div class="flex flex-wrap items-center gap-3">
                <x-button type="submit">Save quote</x-button>
                <a href="{{ route('quotes.index') }}" wire:navigate class="text-sm font-medium text-muted-foreground hover:text-foreground">Cancel</a>
            </div>
        @endif
    </form>

    @if ($quote && $quote->status() === QuoteStatus::Draft)
        @can('send', $quote)
            <section class="rounded-xl border border-border bg-card p-5">
                <h2 class="text-sm font-semibold text-foreground">Send it</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    The PDF goes with it. Once sent, this quote is locked — revising raises a new version.
                </p>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-form.label for="sendSubject">Subject</x-form.label>
                        <x-form.input id="sendSubject" wire:model="sendSubject" placeholder="{{ $quote->reference() }} from {{ config('app.name') }}" />
                    </div>

                    <div>
                        <x-form.label for="sendIntro">Message</x-form.label>
                        <x-form.input id="sendIntro" wire:model="sendIntro" placeholder="Please find our quote attached." />
                    </div>
                </div>

                <x-button type="button" class="mt-4" wire:click="send">
                    <x-icon name="lucide-send" />
                    Send to {{ $quote->bill_to_email ?: 'the customer' }}
                </x-button>
            </section>
        @endcan
    @endif

    @if ($quote && ! $quote->status()->isEditable())
        <section class="rounded-xl border border-border bg-card p-5">
            <h2 class="text-sm font-semibold text-foreground">What happened next</h2>

            <div class="mt-3 flex flex-wrap items-center gap-2">
                @can('send', $quote)
                    @foreach ($quote->status()->allowedTransitions() as $target)
                        @if ($target !== QuoteStatus::Superseded)
                            <button type="button" wire:click="mark('{{ $target->value }}')"
                                    class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted">
                                Mark {{ strtolower($target->label()) }}
                            </button>
                        @endif
                    @endforeach
                @endcan

                @can('revise', $quote)
                    <button type="button" wire:click="revise"
                            class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted">
                        <x-icon name="lucide-copy-plus" />
                        Raise a new version
                    </button>
                @endcan
            </div>
        </section>
    @endif
</div>
