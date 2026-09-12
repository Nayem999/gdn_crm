<div>
    <x-settings-shell
        heading="API keys"
        description="Keys an integration authenticates with. A key acts as the person who created it and sees exactly what they see."
        active="settings.api-tokens"
    >
        @if ($plainTextToken !== null)
            <div class="mb-6 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4">
                <h2 class="text-sm font-semibold text-foreground">Copy this now</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    It is stored hashed, so this is the only time it can be shown. If you lose it, revoke the key and make another.
                </p>
                <code class="mt-2 block overflow-x-auto rounded-lg bg-background px-3 py-2 font-mono text-xs text-foreground">{{ $plainTextToken }}</code>
                <button type="button" wire:click="dismissToken" class="mt-2 text-xs text-muted-foreground underline underline-offset-4 hover:text-foreground">
                    Done
                </button>
            </div>
        @endif

        <form wire:submit="create" class="mb-6 rounded-xl border border-border bg-card p-5 sm:p-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-form.label for="token-name" required>What is it for</x-form.label>
                    <x-form.input id="token-name" wire:model="name" placeholder="Website order feed" />
                    <x-form.error for="name" />
                </div>

                <div>
                    <x-form.label for="token-access" required>Access</x-form.label>
                    <x-select
                        name="token_access"
                        :options="['read' => 'Read only', 'write' => 'Read and write']"
                        :selected="$access"
                        wire:model="access"
                    />
                    <x-form.error for="access" />
                </div>
            </div>

            <div class="mt-4">
                <x-button type="submit">Create key</x-button>
            </div>
        </form>

        <div class="rounded-xl border border-border bg-card">
            @if ($tokens->isEmpty())
                <x-empty-state
                    icon="key-round"
                    heading="No keys yet"
                    description="An integration needs one of these to reach the API."
                />
            @else
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Name</th>
                            <th scope="col" class="px-4 py-3 font-medium">Access</th>
                            <th scope="col" class="px-4 py-3 font-medium">Last used</th>
                            <th scope="col" class="px-4 py-3 font-medium">Created</th>
                            <th scope="col" class="px-4 py-3 font-medium"><span class="sr-only">Revoke</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($tokens as $token)
                            <tr>
                                <td class="px-4 py-3 font-medium text-foreground">{{ $token->name }}</td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ in_array('write', $token->abilities ?? [], true) ? 'Read and write' : 'Read only' }}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ $token->last_used_at?->diffForHumans() ?? 'Never' }}
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">{{ $token->created_at?->diffForHumans() }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        type="button"
                                        wire:click="revoke({{ $token->id }})"
                                        wire:confirm="Revoke this key? Anything using it stops working immediately."
                                        class="text-sm text-destructive underline underline-offset-4"
                                    >
                                        Revoke
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </x-settings-shell>
</div>
