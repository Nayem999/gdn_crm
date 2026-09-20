<div class="space-y-4">
    {{-- Why the inbox is empty, when the reason is on our side. --}}
    <x-stalled-deliveries :stalled="$stalled" />

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-foreground">{{ $this->heading() }}</h1>
            <p class="text-sm text-muted-foreground">
                {{ $this->subheading() }} &middot;
                {{ $waiting }} {{ \Illuminate\Support\Str::plural('conversation', $waiting) }} waiting for a reply.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            {{-- <x-select> rather than a bare one, so every dropdown in the
                 application searches the same way. The UI standard's hardest
                 rule, and there is a sweep test that enforces it. --}}
            <x-select
                name="inbox-channel"
                :options="\App\Domain\Social\Enums\SocialChannel::options()"
                :selected="$channel"
                placeholder="Every channel"
                clearable
                wire:model.live="channel"
                class="w-44"
            />

            <x-select
                name="inbox-status"
                :options="\App\Domain\Social\Enums\ConversationStatus::options()"
                :selected="$status"
                placeholder="Any status"
                clearable
                wire:model.live="status"
                class="w-44"
            />

            <x-select
                name="inbox-owner"
                :options="['mine' => 'Mine', 'unassigned' => 'Nobody\'s yet']"
                :selected="$owner"
                placeholder="Anybody"
                clearable
                wire:model.live="owner"
                class="w-40"
            />

            <x-form.input type="search" wire:model.live.debounce.300ms="search" placeholder="Search by name"
                          aria-label="Search conversations" class="w-48" />
        </div>
    </div>

    @if ($error)
        <x-alert variant="error">{{ $error }}</x-alert>
    @endif

    @if ($notice)
        <x-alert variant="success">{{ $notice }}</x-alert>
    @endif

    <div class="grid gap-4 lg:grid-cols-12">
        {{-- Which conversation ------------------------------------------------ --}}
        <div class="lg:col-span-3">
            <div class="overflow-hidden rounded-xl border border-border bg-card">
                @if ($conversations->isEmpty())
                    <div class="p-6 text-center text-sm text-muted-foreground">
                        Nothing here. Messages appear the moment somebody writes to a connected page or number.

                        @can('viewAny', App\Domain\Meta\Models\MetaAccount::class)
                            {{-- The commonest reason an inbox stays empty is not
                                 that nobody wrote: it is that Meta was never told
                                 where to deliver. Said here, where somebody is
                                 already wondering. --}}
                            <a href="{{ route('settings.meta.connect') }}" wire:navigate class="block pt-2 font-medium underline">
                                Check where replies are delivered
                            </a>
                        @endcan
                    </div>
                @else
                    <ul class="max-h-[32rem] divide-y divide-border overflow-y-auto">
                        @foreach ($conversations as $row)
                            <li>
                                <button
                                    type="button"
                                    wire:click="select({{ $row->id }})"
                                    wire:key="conversation-{{ $row->id }}"
                                    @class([
                                        'flex w-full flex-col gap-1 px-4 py-3 text-left hover:bg-muted',
                                        'bg-muted' => $selected?->id === $row->id,
                                    ])
                                >
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="truncate text-sm font-medium text-foreground">
                                            {{ $row->displayName() }}
                                        </span>
                                        @if ($row->unread_count > 0)
                                            <span class="rounded-full bg-accent px-2 py-0.5 text-xs font-medium text-accent-foreground">
                                                {{ $row->unread_count }}
                                            </span>
                                        @endif
                                    </span>

                                    <span class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <x-icon :name="$row->channel()->icon()" class="h-3.5 w-3.5" />
                                        {{ $row->channel()->label() }}
                                        @if ($row->last_message_at)
                                            &middot; {{ $row->last_message_at->diffForHumans() }}
                                        @endif
                                    </span>

                                    <span class="flex flex-wrap items-center gap-1.5">
                                        <x-status-chip :label="$row->status()->label()" :color="$row->status()->color()" />
                                        @if ($row->assignedTo)
                                            <span class="text-xs text-muted-foreground">{{ $row->assignedTo->name }}</span>
                                        @endif
                                    </span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        {{-- What was said ------------------------------------------------------ --}}
        <div class="lg:col-span-6">
            <div class="flex h-full flex-col rounded-xl border border-border bg-card">
                @if ($selected === null)
                    <div class="flex flex-1 items-center justify-center p-10 text-sm text-muted-foreground">
                        Choose a conversation.
                    </div>
                @else
                    <div class="flex items-center justify-between gap-3 border-b border-border px-5 py-3">
                        <div>
                            <div class="text-sm font-semibold text-foreground">{{ $selected->displayName() }}</div>
                            <div class="text-xs text-muted-foreground">
                                {{ $selected->channel()->label() }}
                                @if ($selected->windowRemaining())
                                    &middot; {{ $selected->windowRemaining() }} to reply
                                @endif
                            </div>
                        </div>

                        @can('assign', $selected)
                            <div class="flex items-center gap-2">
                                @if ($selected->assigned_to_id === auth()->id())
                                    <button type="button" wire:click="release" class="text-xs text-muted-foreground hover:text-foreground">Release</button>
                                @else
                                    <button type="button" wire:click="claim" class="text-xs font-medium text-accent hover:underline">Take it</button>
                                @endif

                                @if ($selected->status()->isClosed())
                                    <button type="button" wire:click="reopen" class="text-xs text-muted-foreground hover:text-foreground">Reopen</button>
                                @else
                                    <button type="button" wire:click="close" class="text-xs text-muted-foreground hover:text-foreground">Close</button>
                                @endif
                            </div>
                        @endcan
                    </div>

                    <div class="flex-1 space-y-3 overflow-y-auto p-5" style="max-height: 24rem">
                        @foreach ($selected->messages as $message)
                            <div @class(['flex', 'justify-end' => ! $message->isInbound()]) wire:key="message-{{ $message->id }}">
                                <div @class([
                                    'max-w-[80%] rounded-2xl px-4 py-2 text-sm',
                                    'bg-muted text-foreground' => $message->isInbound(),
                                    'bg-accent text-accent-foreground' => ! $message->isInbound(),
                                ])>
                                    @if ($message->hasStoredAttachment())
                                        {{-- Behind a policy on the private disk:
                                             these are customer photographs and
                                             signed paperwork. --}}
                                        <a href="{{ route('social.media', $message) }}"
                                           class="flex items-center gap-1.5 font-medium underline underline-offset-4">
                                            <x-icon :name="$message->type()->icon()" class="h-4 w-4" />
                                            {{ $message->type()->label() }}
                                        </a>
                                    @endif

                                    @if ($message->body)
                                        <p class="whitespace-pre-line">{{ $message->body }}</p>
                                    @elseif (! $message->hasStoredAttachment())
                                        <p class="flex items-center gap-1.5 italic opacity-80">
                                            <x-icon :name="$message->type()->icon()" class="h-4 w-4" />
                                            {{ $message->type()->label() }}
                                        </p>
                                    @endif

                                    <p class="mt-1 text-[11px] opacity-70">
                                        {{ $message->created_at?->diffForHumans() }}
                                        @unless ($message->isInbound())
                                            &middot; {{ $message->status()->label() }}
                                            @if ($message->sender)
                                                &middot; {{ $message->sender->name }}
                                            @endif
                                        @endunless
                                    </p>

                                    @if ($message->error)
                                        <p class="mt-1 text-[11px] font-medium">{{ $message->error }}</p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="border-t border-border p-4">
                        @php($refusal = $selected->windowRefusal())

                        @if ($refusal)
                            {{-- Meta's rule, stated rather than worked around. The
                                 send action refuses independently: a disabled box
                                 is a courtesy, not a boundary. --}}
                            <x-alert variant="info">{{ $refusal }}</x-alert>

                            @if ($templates !== [] && auth()->user()->can('reply', $selected))
                                {{-- The one thing Meta still allows: text it has
                                     already approved, sent by name with its values
                                     as parameters. --}}
                                <div class="mt-4 space-y-3">
                                    <x-select
                                        name="inbox-template"
                                        label="Send an approved template"
                                        :options="$templates"
                                        :selected="$templateId"
                                        placeholder="Choose a template..."
                                        wire:model.live="templateId"
                                    />

                                    @if ($chosenTemplate)
                                        <div wire:key="template-{{ $chosenTemplate->id }}" class="space-y-2">
                                            <p class="rounded-lg bg-muted px-3 py-2 text-sm text-muted-foreground">
                                                {{ $chosenTemplate->body }}
                                            </p>

                                            {{-- No literal braces in the label: Blade
                                                 compiles `{{` wherever it appears,
                                                 attributes included. The body above
                                                 already shows where each value
                                                 lands. --}}
                                            @foreach ($chosenTemplate->variables ?? [] as $index => $placeholder)
                                                <x-form.input
                                                    wire:model="templateValues.{{ $index }}"
                                                    placeholder="Value {{ $placeholder }}"
                                                    aria-label="Value for placeholder {{ $placeholder }}"
                                                />
                                            @endforeach

                                            <x-button type="button" wire:click="sendTemplate">Send template</x-button>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        @elseif (auth()->user()->can('reply', $selected))
                            <form wire:submit="send" class="flex items-end gap-2">
                                <textarea
                                    wire:model="reply"
                                    rows="2"
                                    placeholder="Write a reply…"
                                    class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                                ></textarea>

                                <x-button type="submit" wire:loading.attr="disabled">Send</x-button>
                            </form>
                        @else
                            <p class="text-sm text-muted-foreground">You can read this conversation but not reply to it.</p>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        {{-- What to do about it ------------------------------------------------ --}}
        <div class="lg:col-span-3">
            @if ($selected !== null)
                <div class="space-y-4 rounded-xl border border-border bg-card p-4">
                    @php($referral = $selected->referral())

                    @if ($referral !== null)
                        {{-- Which advertisement started this. Worth the space at
                             the top of the panel: an agent who knows what the
                             customer was promised answers the question they
                             actually have. --}}
                        <div class="rounded-lg border border-border bg-background p-3">
                            <h2 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Came from an advertisement
                            </h2>

                            <p class="mt-1.5 text-sm font-medium text-foreground">{{ $referral->label() }}</p>

                            @if ($referral->body)
                                <p class="mt-1 text-xs text-muted-foreground">{{ $referral->body }}</p>
                            @endif

                            @if ($this->referralCampaign() !== null)
                                <p class="mt-2 text-xs text-muted-foreground">
                                    Campaign: {{ $this->referralCampaign() }}
                                </p>
                            @endif
                        </div>
                    @endif

                    <div>
                        <h2 class="text-sm font-semibold text-foreground">In the CRM</h2>

                        @if ($subject)
                            <a href="{{ $this->subjectRoute($subject) }}" wire:navigate
                               class="mt-2 block text-sm font-medium text-foreground underline decoration-border underline-offset-4">
                                {{ $subject->fullName() }}
                            </a>
                            <p class="text-xs text-muted-foreground">
                                {{ $subject instanceof \App\Domain\Contacts\Models\Contact ? 'Contact' : 'Lead' }}
                            </p>
                        @else
                            <p class="mt-2 text-sm text-muted-foreground">Not linked to anybody yet.</p>

                            @can('assign', $selected)
                                <x-button type="button" variant="secondary" wire:click="createLead" class="mt-3">
                                    Create a lead
                                </x-button>
                            @endcan
                        @endif
                    </div>

                    @can('assign', $selected)
                        <div class="border-t border-border pt-4">
                            <label for="inbox-note" class="text-sm font-semibold text-foreground">Add a note</label>
                            <textarea
                                id="inbox-note"
                                wire:model="note"
                                rows="3"
                                class="mt-2 w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/40"
                            ></textarea>
                            <x-button type="button" variant="secondary" wire:click="addNote" class="mt-2">Save note</x-button>
                        </div>

                        <div class="border-t border-border pt-4">
                            <p class="text-sm font-semibold text-foreground">Add a task</p>

                            <x-form.input wire:model="taskSubject" placeholder="Follow up" aria-label="Task" class="mt-2" />
                            <x-form.input type="date" wire:model="taskDueAt" aria-label="Due date" class="mt-2" />

                            <x-button type="button" variant="secondary" wire:click="addTask" class="mt-2">Save task</x-button>
                        </div>
                    @endcan
                </div>
            @endif
        </div>
    </div>
</div>
