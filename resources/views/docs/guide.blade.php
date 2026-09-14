{{-- The public documentation page.

     Deliberately not built on components/layouts/app.blade.php: that shell
     assumes a signed-in user for the sidebar, the notification bell and the
     avatar menu, and this page is the one thing in the application a stranger
     is meant to read.

     It carries its own typography rules because the body is HTML generated from
     markdown — there are no Blade elements to hang utility classes on, and the
     project has no typography plugin. Everything below is written against the
     same CSS variables the rest of the application uses, so light and dark are
     the reader's choice here as well. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        @include('layouts.partials.head', ['title' => 'Documentation'])

        <style>
            .doc-body { color: var(--foreground); font-size: 0.95rem; line-height: 1.7; }

            .doc-body h2 {
                margin: 2.75rem 0 1rem;
                padding-top: 1.75rem;
                border-top: 1px solid var(--border);
                font-size: 1.35rem;
                font-weight: 700;
                letter-spacing: -0.01em;
                scroll-margin-top: 6rem;
            }

            .doc-body h2:first-child { margin-top: 0; padding-top: 0; border-top: 0; }

            .doc-body h3 {
                margin: 2rem 0 0.75rem;
                font-size: 1.05rem;
                font-weight: 650;
                scroll-margin-top: 6rem;
            }

            .doc-body p { margin: 0.9rem 0; }

            .doc-body ul,
            .doc-body ol { margin: 0.9rem 0; padding-left: 1.4rem; }

            .doc-body ul { list-style: disc; }
            .doc-body ol { list-style: decimal; }
            .doc-body li { margin: 0.4rem 0; }
            .doc-body li > ul,
            .doc-body li > ol { margin: 0.35rem 0; }

            .doc-body a { color: var(--accent); text-decoration: underline; text-underline-offset: 2px; }
            .doc-body strong { font-weight: 650; color: var(--foreground); }

            .doc-body code {
                padding: 0.1rem 0.35rem;
                border-radius: 0.35rem;
                background: var(--muted);
                color: var(--foreground);
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-size: 0.85em;
            }

            .doc-body pre {
                margin: 1.1rem 0;
                padding: 1rem 1.1rem;
                overflow-x: auto;
                border: 1px solid var(--border);
                border-radius: 0.75rem;
                background: var(--card);
                line-height: 1.6;
            }

            .doc-body pre code { padding: 0; background: none; font-size: 0.85rem; }

            .doc-body blockquote {
                margin: 1.1rem 0;
                padding: 0.35rem 0 0.35rem 1rem;
                border-left: 3px solid var(--accent);
                color: var(--muted-foreground);
            }

            .doc-body hr { margin: 2.5rem 0; border: 0; border-top: 1px solid var(--border); }

            {{-- Tables are the guides' densest content and the first thing to
                 break on a phone, so each one scrolls inside itself rather than
                 pushing the page sideways. --}}
            .doc-body .table-scroll { margin: 1.25rem 0; overflow-x: auto; }

            .doc-body table {
                width: 100%;
                border-collapse: collapse;
                font-size: 0.875rem;
                min-width: 32rem;
            }

            .doc-body th,
            .doc-body td {
                padding: 0.6rem 0.8rem;
                border-bottom: 1px solid var(--border);
                text-align: left;
                vertical-align: top;
            }

            .doc-body th { font-weight: 650; background: var(--muted); }
            .doc-body tbody tr:last-child td { border-bottom: 0; }
        </style>
    </head>

    <body class="min-h-full bg-background font-sans text-foreground antialiased">
        <a href="#content" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-card focus:px-4 focus:py-2 focus:text-sm focus:shadow">
            Skip to the documentation
        </a>

        <header class="sticky top-0 z-40 border-b border-border bg-background/95 backdrop-blur">
            <div class="mx-auto flex h-16 max-w-7xl items-center gap-3 px-4 sm:px-6 lg:px-8">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-accent text-accent-foreground">
                    <x-lucide-building-2 class="h-5 w-5" aria-hidden="true" />
                </span>

                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-foreground">{{ config('app.name') }}</p>
                    <p class="truncate text-xs text-muted-foreground">Documentation</p>
                </div>

                <div class="ml-auto flex items-center gap-2">
                    <button
                        type="button"
                        onclick="document.documentElement.classList.toggle('dark'); localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');"
                        class="flex h-10 w-10 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground"
                        aria-label="Toggle dark mode"
                    >
                        <x-lucide-sun class="h-5 w-5 dark:hidden" aria-hidden="true" />
                        <x-lucide-moon class="hidden h-5 w-5 dark:block" aria-hidden="true" />
                    </button>

                    <a
                        href="{{ route('login') }}"
                        class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90"
                    >
                        Sign in
                    </a>
                </div>
            </div>
        </header>

        <div class="mx-auto flex max-w-7xl gap-10 px-4 py-10 sm:px-6 lg:px-8">
            {{-- The contents list. Hidden below lg rather than collapsed into a
                 menu: on a narrow screen the page itself is the shortest way
                 through, and a sticky panel would eat half the screen. --}}
            <nav class="hidden w-64 shrink-0 lg:block" aria-label="Contents">
                <div class="sticky top-24 max-h-[calc(100vh-8rem)] overflow-y-auto pb-10">
                    @foreach ($sections as $section)
                        <p class="mb-2 mt-6 text-xs font-semibold uppercase tracking-wide text-muted-foreground first:mt-0">
                            {{ $section['title'] }}
                        </p>

                        <ul class="space-y-1 border-l border-border">
                            @foreach ($section['contents'] as $entry)
                                <li>
                                    <a
                                        href="#{{ $entry['id'] }}"
                                        class="-ml-px block border-l border-transparent py-1 pl-3 text-sm text-muted-foreground hover:border-accent hover:text-foreground"
                                    >{{ $entry['title'] }}</a>
                                </li>
                            @endforeach
                        </ul>
                    @endforeach

                    <p class="mb-2 mt-6 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        Diagram
                    </p>

                    <ul class="space-y-1 border-l border-border">
                        <li>
                            <a href="#workflow" class="-ml-px block border-l border-transparent py-1 pl-3 text-sm text-muted-foreground hover:border-accent hover:text-foreground">
                                How the work flows
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <main id="content" class="min-w-0 flex-1">
                <div class="mb-12 rounded-xl border border-border bg-card p-6 sm:p-8">
                    <h1 class="text-2xl font-bold tracking-tight text-card-foreground sm:text-3xl">
                        {{ config('app.name') }} documentation
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                        Everything needed to work in the CRM and to run it. No account required to read this —
                        you will need one to follow along.
                    </p>

                    <div class="mt-5 flex flex-wrap gap-2">
                        @foreach ($sections as $section)
                            <a
                                href="#{{ $section['key'] }}"
                                class="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-1.5 text-sm font-medium text-foreground hover:bg-muted"
                            >{{ $section['title'] }}</a>
                        @endforeach

                        <a
                            href="#workflow"
                            class="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-1.5 text-sm font-medium text-foreground hover:bg-muted"
                        >Workflow diagram</a>
                    </div>
                </div>

                @foreach ($sections as $section)
                    <section id="{{ $section['key'] }}" class="mb-16 scroll-mt-24">
                        <div class="mb-8">
                            <h2 class="text-xl font-bold tracking-tight text-foreground sm:text-2xl">{{ $section['title'] }}</h2>
                            <p class="mt-1 text-sm text-muted-foreground">{{ $section['summary'] }}</p>
                        </div>

                        {{-- Generated from the repository's own markdown, with raw
                             HTML stripped on the way through. --}}
                        <x-markdown :html="$section['html']" />
                    </section>
                @endforeach

                <section id="workflow" class="scroll-mt-24">
                    <div class="mb-8 border-t border-border pt-8">
                        <h2 class="text-xl font-bold tracking-tight text-foreground sm:text-2xl">How the work flows</h2>
                        <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                            One picture of the route a piece of business takes through the CRM: from wherever it
                            arrives, to a customer record, through the pipeline, into the documents that get paid —
                            with support running alongside and automation across the lot.
                        </p>
                    </div>

                    <div class="overflow-x-auto rounded-xl border border-border bg-card p-4 sm:p-6">
                        <svg
                            viewBox="0 0 980 800"
                            role="img"
                            aria-labelledby="workflow-title workflow-desc"
                            class="h-auto w-full"
                            style="min-width: 720px;"
                        >
                            <title id="workflow-title">The CRM workflow</title>
                            <desc id="workflow-desc">
                                Leads arrive from a web form, the chat widget, inbound email or an import, are scored
                                and qualified, and convert into an account, a contact and a deal. The deal moves
                                through the pipeline stages and, once won, becomes a quote, a sales order, an invoice
                                and a payment. Support tickets run alongside the sales flow, and workflows,
                                notifications, the audit trail and reporting run across every step.
                            </desc>

                            <defs>
                                <marker id="wf-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                                    <path d="M 0 0 L 10 5 L 0 10 z" fill="var(--muted-foreground)" />
                                </marker>
                                <marker id="wf-arrow-accent" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                                    <path d="M 0 0 L 10 5 L 0 10 z" fill="var(--accent)" />
                                </marker>
                            </defs>

                            <g font-family="inherit" text-anchor="middle">
                                {{-- 1. Where work arrives ------------------------------------ --}}
                                <text x="40" y="24" text-anchor="start" font-size="11" font-weight="700" letter-spacing="1" fill="var(--muted-foreground)">IT ARRIVES</text>

                                <g>
                                    <rect x="40" y="36" width="200" height="52" rx="10" fill="var(--card)" stroke="var(--border)" stroke-width="1.5" />
                                    <text x="140" y="67" font-size="14" font-weight="600" fill="var(--foreground)">Web form</text>
                                </g>
                                <g>
                                    <rect x="273" y="36" width="200" height="52" rx="10" fill="var(--card)" stroke="var(--border)" stroke-width="1.5" />
                                    <text x="373" y="67" font-size="14" font-weight="600" fill="var(--foreground)">Chat widget</text>
                                </g>
                                <g>
                                    <rect x="506" y="36" width="200" height="52" rx="10" fill="var(--card)" stroke="var(--border)" stroke-width="1.5" />
                                    <text x="606" y="67" font-size="14" font-weight="600" fill="var(--foreground)">Inbound email</text>
                                </g>
                                <g>
                                    <rect x="739" y="36" width="200" height="52" rx="10" fill="var(--card)" stroke="var(--border)" stroke-width="1.5" />
                                    <text x="839" y="67" font-size="14" font-weight="600" fill="var(--foreground)">Import &amp; API</text>
                                </g>

                                <g fill="none" stroke="var(--muted-foreground)" stroke-width="1.5" marker-end="url(#wf-arrow)">
                                    <path d="M140,88 C140,122 420,112 460,144" />
                                    <path d="M373,88 C373,118 450,118 475,144" />
                                    <path d="M606,88 C606,118 530,118 505,144" />
                                    <path d="M839,88 C839,122 560,112 520,144" />
                                </g>

                                {{-- 2. The lead ---------------------------------------------- --}}
                                <rect x="330" y="146" width="320" height="60" rx="12" fill="var(--card)" stroke="var(--accent)" stroke-width="2" />
                                <text x="490" y="172" font-size="15" font-weight="700" fill="var(--foreground)">Lead</text>
                                <text x="490" y="191" font-size="11.5" fill="var(--muted-foreground)">captured, scored, qualified</text>

                                <path d="M490,206 L490,246" fill="none" stroke="var(--accent)" stroke-width="1.5" marker-end="url(#wf-arrow-accent)" />
                                <text x="502" y="232" text-anchor="start" font-size="11.5" font-weight="600" fill="var(--accent)">Convert</text>

                                {{-- 3. What it becomes --------------------------------------- --}}
                                <text x="40" y="240" text-anchor="start" font-size="11" font-weight="700" letter-spacing="1" fill="var(--muted-foreground)">IT BECOMES</text>

                                <g>
                                    <rect x="40" y="252" width="260" height="56" rx="10" fill="var(--card)" stroke="var(--border)" stroke-width="1.5" />
                                    <text x="170" y="277" font-size="14" font-weight="600" fill="var(--foreground)">Account</text>
                                    <text x="170" y="295" font-size="11.5" fill="var(--muted-foreground)">the organisation</text>
                                </g>
                                <g>
                                    <rect x="360" y="252" width="260" height="56" rx="10" fill="var(--card)" stroke="var(--border)" stroke-width="1.5" />
                                    <text x="490" y="277" font-size="14" font-weight="600" fill="var(--foreground)">Contact</text>
                                    <text x="490" y="295" font-size="11.5" fill="var(--muted-foreground)">the person</text>
                                </g>
                                <g>
                                    <rect x="680" y="252" width="260" height="56" rx="10" fill="var(--card)" stroke="var(--accent)" stroke-width="2" />
                                    <text x="810" y="277" font-size="14" font-weight="700" fill="var(--foreground)">Deal</text>
                                    <text x="810" y="295" font-size="11.5" fill="var(--muted-foreground)">the opportunity</text>
                                </g>

                                <path d="M810,308 L810,352" fill="none" stroke="var(--muted-foreground)" stroke-width="1.5" marker-end="url(#wf-arrow)" />

                                {{-- 4. The pipeline ------------------------------------------ --}}
                                <rect x="40" y="356" width="900" height="94" rx="14" fill="none" stroke="var(--border)" stroke-width="1.5" stroke-dasharray="5 4" />
                                <text x="56" y="378" text-anchor="start" font-size="11" font-weight="700" letter-spacing="1" fill="var(--muted-foreground)">DEAL PIPELINE</text>

                                <g>
                                    <rect x="55" y="392" width="158" height="44" rx="8" fill="var(--muted)" stroke="var(--border)" stroke-width="1" />
                                    <text x="134" y="419" font-size="13" font-weight="600" fill="var(--foreground)">New</text>
                                </g>
                                <g>
                                    <rect x="233" y="392" width="158" height="44" rx="8" fill="var(--muted)" stroke="var(--border)" stroke-width="1" />
                                    <text x="312" y="419" font-size="13" font-weight="600" fill="var(--foreground)">Qualification</text>
                                </g>
                                <g>
                                    <rect x="411" y="392" width="158" height="44" rx="8" fill="var(--muted)" stroke="var(--border)" stroke-width="1" />
                                    <text x="490" y="419" font-size="13" font-weight="600" fill="var(--foreground)">Proposal</text>
                                </g>
                                <g>
                                    <rect x="589" y="392" width="158" height="44" rx="8" fill="var(--muted)" stroke="var(--border)" stroke-width="1" />
                                    <text x="668" y="419" font-size="13" font-weight="600" fill="var(--foreground)">Negotiation</text>
                                </g>
                                <g>
                                    <rect x="767" y="392" width="158" height="44" rx="8" fill="var(--card)" stroke="var(--accent)" stroke-width="1.75" />
                                    <text x="846" y="419" font-size="13" font-weight="700" fill="var(--foreground)">Won / Lost</text>
                                </g>

                                <g fill="none" stroke="var(--muted-foreground)" stroke-width="1.5" marker-end="url(#wf-arrow)">
                                    <path d="M213,414 L231,414" />
                                    <path d="M391,414 L409,414" />
                                    <path d="M569,414 L587,414" />
                                    <path d="M747,414 L765,414" />
                                </g>

                                <path d="M490,450 L490,488" fill="none" stroke="var(--accent)" stroke-width="1.5" marker-end="url(#wf-arrow-accent)" />
                                <text x="502" y="474" text-anchor="start" font-size="11.5" font-weight="600" fill="var(--accent)">Won</text>

                                {{-- 5. Getting paid ------------------------------------------ --}}
                                <text x="40" y="482" text-anchor="start" font-size="11" font-weight="700" letter-spacing="1" fill="var(--muted-foreground)">IT GETS PAID</text>

                                <g>
                                    <rect x="40" y="492" width="200" height="56" rx="10" fill="var(--card)" stroke="var(--border)" stroke-width="1.5" />
                                    <text x="140" y="517" font-size="14" font-weight="600" fill="var(--foreground)">Quote</text>
                                    <text x="140" y="535" font-size="11.5" fill="var(--muted-foreground)">sent, versioned</text>
                                </g>
                                <g>
                                    <rect x="273" y="492" width="200" height="56" rx="10" fill="var(--card)" stroke="var(--border)" stroke-width="1.5" />
                                    <text x="373" y="517" font-size="14" font-weight="600" fill="var(--foreground)">Sales order</text>
                                    <text x="373" y="535" font-size="11.5" fill="var(--muted-foreground)">from an accepted quote</text>
                                </g>
                                <g>
                                    <rect x="506" y="492" width="200" height="56" rx="10" fill="var(--card)" stroke="var(--border)" stroke-width="1.5" />
                                    <text x="606" y="517" font-size="14" font-weight="600" fill="var(--foreground)">Invoice</text>
                                    <text x="606" y="535" font-size="11.5" fill="var(--muted-foreground)">issued</text>
                                </g>
                                <g>
                                    <rect x="739" y="492" width="200" height="56" rx="10" fill="var(--card)" stroke="var(--border)" stroke-width="1.5" />
                                    <text x="839" y="517" font-size="14" font-weight="600" fill="var(--foreground)">Payment</text>
                                    <text x="839" y="535" font-size="11.5" fill="var(--muted-foreground)">recorded against it</text>
                                </g>

                                <g fill="none" stroke="var(--muted-foreground)" stroke-width="1.5" marker-end="url(#wf-arrow)">
                                    <path d="M240,520 L271,520" />
                                    <path d="M473,520 L504,520" />
                                    <path d="M706,520 L737,520" />
                                </g>

                                {{-- 6. Support, alongside ------------------------------------ --}}
                                <line x1="40" y1="584" x2="940" y2="584" stroke="var(--border)" stroke-width="1" stroke-dasharray="4 5" />

                                <text x="40" y="610" text-anchor="start" font-size="11" font-weight="700" letter-spacing="1" fill="var(--muted-foreground)">SUPPORT RUNS ALONGSIDE</text>

                                <g>
                                    <rect x="40" y="622" width="200" height="52" rx="10" fill="var(--card)" stroke="var(--border)" stroke-width="1.5" />
                                    <text x="140" y="653" font-size="14" font-weight="600" fill="var(--foreground)">Ticket raised</text>
                                </g>
                                <g>
                                    <rect x="273" y="622" width="200" height="52" rx="10" fill="var(--card)" stroke="var(--border)" stroke-width="1.5" />
                                    <text x="373" y="647" font-size="14" font-weight="600" fill="var(--foreground)">Assigned</text>
                                    <text x="373" y="664" font-size="11.5" fill="var(--muted-foreground)">SLA clock running</text>
                                </g>
                                <g>
                                    <rect x="506" y="622" width="200" height="52" rx="10" fill="var(--card)" stroke="var(--border)" stroke-width="1.5" />
                                    <text x="606" y="647" font-size="14" font-weight="600" fill="var(--foreground)">Answered</text>
                                    <text x="606" y="664" font-size="11.5" fill="var(--muted-foreground)">from the knowledge base</text>
                                </g>
                                <g>
                                    <rect x="739" y="622" width="200" height="52" rx="10" fill="var(--card)" stroke="var(--border)" stroke-width="1.5" />
                                    <text x="839" y="653" font-size="14" font-weight="600" fill="var(--foreground)">Resolved &amp; closed</text>
                                </g>

                                <g fill="none" stroke="var(--muted-foreground)" stroke-width="1.5" marker-end="url(#wf-arrow)">
                                    <path d="M240,648 L271,648" />
                                    <path d="M473,648 L504,648" />
                                    <path d="M706,648 L737,648" />
                                </g>

                                {{-- 7. What runs across all of it ---------------------------- --}}
                                <rect x="40" y="712" width="900" height="64" rx="14" fill="none" stroke="var(--accent)" stroke-width="1.75" stroke-dasharray="6 5" />
                                <text x="490" y="738" font-size="13" font-weight="700" fill="var(--foreground)">Workflows, notifications and the audit trail run across every step</text>
                                <text x="490" y="758" font-size="11.5" fill="var(--muted-foreground)">and the reports and the forecast read all of it</text>
                            </g>
                        </svg>
                    </div>

                    <p class="mt-4 text-xs text-muted-foreground">
                        Each box is a screen in the sidebar. The dashed rail at the bottom is not a screen —
                        it is what happens on its own once an administrator has configured it.
                    </p>
                </section>
            </main>
        </div>

        <footer class="border-t border-border">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-8 text-xs text-muted-foreground sm:px-6 lg:px-8">
                <span>{{ config('app.name') }} documentation</span>
                <span aria-hidden="true">&middot;</span>
                <a href="{{ route('login') }}" class="font-medium text-accent hover:underline">Sign in</a>
            </div>
        </footer>
    </body>
</html>
