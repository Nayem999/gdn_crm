@php
    use App\Domain\Settings\NumberFormat;
    use App\Domain\Shared\UI\ChipPalette;

    $bands = $this->bands;
    $pipeline = $this->pipeline;
@endphp

<div class="rounded-xl border border-border bg-card p-5">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-base font-semibold text-foreground">Pipeline</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">
                @if ($pipeline)
                    {{ $this->totalDeals() }} {{ \Illuminate\Support\Str::plural('deal', $this->totalDeals()) }}
                    across {{ $pipeline->displayName() }}
                @else
                    Deals by stage
                @endif
            </p>
        </div>

        {{-- Only worth a switcher when there is something to switch between. --}}
        @if (count($this->pipelines) > 1)
            <div class="flex flex-wrap items-center gap-1">
                @foreach ($this->pipelines as $option)
                    <button
                        type="button"
                        wire:key="funnel-pipeline-{{ $option->getKey() }}"
                        wire:click="selectPipeline({{ $option->getKey() }})"
                        @class([
                            'rounded-full border px-2.5 py-1 text-xs font-medium transition-colors',
                            'border-accent bg-accent/10 text-accent' => $pipeline?->getKey() === $option->getKey(),
                            'border-border text-muted-foreground hover:text-foreground' => $pipeline?->getKey() !== $option->getKey(),
                        ])
                        aria-pressed="{{ $pipeline?->getKey() === $option->getKey() ? 'true' : 'false' }}"
                    >
                        {{ $option->displayName() }}
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    @if (! $this->canSeeDeals())
        <x-empty-state
            icon="lock"
            heading="Deals are not yours to see"
            description="Ask an administrator for the deals permission if you need the pipeline."
        />
    @elseif ($pipeline === null)
        <x-empty-state
            icon="git-branch"
            heading="No pipeline configured"
            description="Set up a pipeline and its stages, and the funnel fills itself in."
        >
            <x-slot:actions>
                @can('viewAny', App\Domain\Deals\Models\Pipeline::class)
                    <a href="{{ route('settings.pipelines') }}" wire:navigate
                       class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90">
                        <x-icon name="lucide-plus" />
                        Set up a pipeline
                    </a>
                @endcan
            </x-slot:actions>
        </x-empty-state>
    @else
        <ol class="space-y-3" wire:loading.class="opacity-60" wire:target="selectPipeline">
            @foreach ($bands as $band)
                <li wire:key="band-{{ $pipeline->getKey() }}-{{ $band->key }}">
                    <div class="flex items-baseline justify-between gap-3 text-sm">
                        <span class="flex min-w-0 items-center gap-2">
                            <span class="h-2 w-2 shrink-0 rounded-full {{ ChipPalette::dotClasses($band->color()) }}"></span>
                            <span class="truncate font-medium text-foreground">{{ $band->name }}</span>
                            <span class="shrink-0 text-xs text-muted-foreground">{{ $band->probability }}%</span>
                        </span>

                        <span class="shrink-0 tabular-nums text-muted-foreground">
                            <span class="font-semibold text-foreground">{{ NumberFormat::format($band->count, 0) }}</span>
                            @if ($band->value > 0)
                                &middot; {{ NumberFormat::format($band->value, 0) }}
                            @endif
                        </span>
                    </div>

                    {{-- The bar is a shape, not the figure: it is drawn against
                         the widest band so eight stages stay readable, and the
                         count beside it is what people actually read. --}}
                    <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-muted">
                        <div
                            class="h-full rounded-full {{ ChipPalette::dotClasses($band->color()) }}"
                            style="width: {{ $band->share }}%"
                            role="img"
                            aria-label="{{ $band->name }}: {{ $band->count }} {{ \Illuminate\Support\Str::plural('deal', $band->count) }}"
                        ></div>
                    </div>
                </li>
            @endforeach
        </ol>

        @if ($this->totalDeals() === 0)
            <p class="mt-4 rounded-lg border border-dashed border-border px-3 py-4 text-center text-sm text-muted-foreground">
                No deals on this pipeline yet. Every stage is listed so you can see what it will look like.
            </p>
        @endif
    @endif
</div>
