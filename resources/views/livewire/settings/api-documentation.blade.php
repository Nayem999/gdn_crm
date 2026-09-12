<div>
    <x-settings-shell
        heading="API documentation"
        description="Generated from the code that serves the API, so it cannot fall out of step with it."
        active="settings.api-docs"
    >
        <div class="space-y-6">
            <div class="rounded-xl border border-border bg-card p-5 sm:p-6">
                <h2 class="text-sm font-semibold text-foreground">{{ $info['title'] }} <span class="font-normal text-muted-foreground">v{{ $info['version'] }}</span></h2>

                <div class="mt-3 space-y-2 text-sm text-muted-foreground">
                    @foreach (explode("\n\n", $info['description']) as $paragraph)
                        <p>{{ strip_tags(str_replace(['**', '`'], '', $paragraph)) }}</p>
                    @endforeach
                </div>

                <dl class="mt-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Base address</dt>
                        <dd><code class="text-foreground">{{ $server }}</code></dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Authentication</dt>
                        <dd class="text-foreground"><code>Authorization: Bearer &lt;your key&gt;</code></dd>
                    </div>
                </dl>

                <p class="mt-4 text-xs text-muted-foreground">
                    The machine-readable description is at
                    <code class="text-foreground">{{ $server }}/openapi.json</code> — the same document this page is rendered from.
                    It needs a key, like everything else on the API.
                </p>
            </div>

            <div class="rounded-xl border border-border bg-card">
                <h2 class="border-b border-border px-5 py-3 text-sm font-semibold text-foreground">Endpoints</h2>

                <table class="w-full text-left text-sm">
                    <tbody class="divide-y divide-border">
                        @foreach ($this->operations() as $operation)
                            <tr>
                                <td class="whitespace-nowrap px-5 py-3 align-top">
                                    <code class="font-semibold text-foreground">{{ $operation['method'] }}</code>
                                </td>
                                <td class="px-3 py-3 align-top">
                                    <code class="text-foreground">{{ $operation['path'] }}</code>
                                </td>
                                <td class="px-5 py-3 align-top">
                                    <span class="text-foreground">{{ $operation['summary'] }}</span>
                                    @if ($operation['description'])
                                        <span class="mt-0.5 block text-xs text-muted-foreground">{{ $operation['description'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="rounded-xl border border-border bg-card p-5 sm:p-6">
                <h2 class="text-sm font-semibold text-foreground">What a record looks like</h2>

                <div class="mt-4 grid grid-cols-1 gap-5 sm:grid-cols-2">
                    @foreach ($this->recordShapes() as $name => $fields)
                        <div>
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ $name }}</h3>
                            <dl class="mt-2 space-y-1 text-sm">
                                @foreach ($fields as $field => $type)
                                    <div class="flex flex-wrap items-baseline gap-2">
                                        <dt><code class="text-foreground">{{ $field }}</code></dt>
                                        <dd class="text-xs text-muted-foreground">{{ $type }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-xl border border-border bg-card p-5 sm:p-6">
                <h2 class="text-sm font-semibold text-foreground">Webhooks</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    What we send you, unasked. Configure an endpoint under Settings &rarr; Webhooks.
                </p>

                <div class="mt-3 flex flex-wrap gap-1.5">
                    @foreach ($this->webhookEvents() as $event)
                        <code class="rounded bg-muted px-2 py-1 text-xs text-muted-foreground">{{ $event }}</code>
                    @endforeach
                </div>

                <div class="mt-4 space-y-2 text-sm text-muted-foreground">
                    <p>
                        Verify the <code class="text-foreground">{{ $this->signatureHeader() }}</code> header before trusting the body.
                        It reads <code class="text-foreground">t=&lt;unix&gt;,v1=&lt;hex&gt;</code>, where the signature is
                        HMAC-SHA256 of <code class="text-foreground">"{t}.{raw body}"</code> using the endpoint's secret.
                    </p>
                    <p>
                        Refuse anything whose timestamp is more than a few minutes old. That is what stops a captured
                        request being replayed later — a signature over the body alone would verify for ever.
                    </p>
                    <p>
                        Answer 2xx. Anything else is retried with a growing gap for about an hour, then given up on and
                        shown as failed on the webhooks screen.
                    </p>
                </div>
            </div>
        </div>
    </x-settings-shell>
</div>
