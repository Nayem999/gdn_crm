@php
    use App\Domain\Leads\Capture\CaptureTimestamp;
    use App\Domain\Leads\Models\LeadCaptureForm;
@endphp

<x-lead-capture.layout :form="$form">
    <div class="rounded-xl border border-border bg-card p-6">
        <h1 class="text-xl font-semibold text-foreground">{{ $form->name }}</h1>

        @if ($form->description)
            <p class="mt-1 text-sm text-muted-foreground">{{ $form->description }}</p>
        @endif

        @unless ($form->is_active)
            <div class="mt-4 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-300">
                This form is no longer accepting submissions.
            </div>
        @endunless

        @if ($errors->any())
            <div class="mt-4 rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive" role="alert">
                <ul class="list-inside list-disc space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('lead-capture.submit', $form->token) }}" class="mt-5 space-y-4">
            {{-- No @csrf: the form is embedded cross-origin where the session
                 cookie is a third-party cookie. See bootstrap/app.php for what
                 stands in for it. --}}

            {{-- The honeypot. Hidden from people by CSS and from screen readers
                 by aria-hidden, and left out of the tab order — so nobody can
                 fill it in by accident, which would silently discard a real
                 enquiry. --}}
            <div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;height:0;overflow:hidden">
                <label for="{{ LeadCaptureForm::HONEYPOT }}">Leave this empty</label>
                <input
                    type="text"
                    id="{{ LeadCaptureForm::HONEYPOT }}"
                    name="{{ LeadCaptureForm::HONEYPOT }}"
                    tabindex="-1"
                    autocomplete="off"
                    value=""
                >
            </div>

            {{-- Signed, so it cannot be back-dated by a script posting an older
                 value. --}}
            <input type="hidden" name="{{ LeadCaptureForm::TIMESTAMP }}" value="{{ CaptureTimestamp::issue() }}">

            @foreach ($fields as $field)
                <div>
                    <label for="lc-{{ $field->key }}" class="mb-1.5 block text-sm font-medium text-foreground">
                        {{ $field->label }}
                        @if ($field->required)
                            <span class="text-destructive" aria-hidden="true">*</span>
                        @endif
                    </label>

                    @if ($field->type === 'textarea')
                        <textarea
                            id="lc-{{ $field->key }}"
                            name="{{ $field->key }}"
                            rows="4"
                            @required($field->required)
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                        >{{ old($field->key) }}</textarea>
                    @else
                        <input
                            type="{{ $field->type }}"
                            id="lc-{{ $field->key }}"
                            name="{{ $field->key }}"
                            value="{{ old($field->key) }}"
                            @required($field->required)
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                        >
                    @endif

                    @error($field->key)
                        <p class="mt-1 text-sm text-destructive">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach

            <button
                type="submit"
                @disabled(! $form->is_active)
                class="w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
            >
                {{ $form->submit_label }}
            </button>
        </form>
    </div>
</x-lead-capture.layout>
