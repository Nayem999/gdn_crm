@props(['stalled' => ['count' => 0, 'oldest' => null]])

{{-- Nothing is running.

     The failure this names produces no error, no failed row and no red
     anything: deliveries arrive, are logged, are queued, and stop. The inbox
     then looks exactly like an inbox nobody has messaged, which is how five
     real messages sat unnoticed for four days.

     Shown wherever somebody would otherwise conclude "nothing came in". --}}
@if (($stalled['count'] ?? 0) > 0)
    @php($oldest = $stalled['oldest'])

    <div class="mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 dark:border-amber-500/30 dark:bg-amber-500/10">
        <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-amber-900 dark:text-amber-200">
            <x-icon name="lucide-triangle-alert" class="h-4 w-4 shrink-0" />
            {{ $stalled['count'] }} {{ Str::plural('delivery', $stalled['count']) }}
            {{ $stalled['count'] === 1 ? 'has' : 'have' }} arrived and
            {{ $stalled['count'] === 1 ? 'is' : 'are' }} waiting to be processed
        </p>

        <p class="mt-0.5 text-sm text-amber-800/80 dark:text-amber-200/70">
            @if ($oldest !== null)
                The oldest has been waiting since {{ $oldest->format('j M, H:i') }} ({{ $oldest->diffForHumans(short: true) }}).
            @endif
            Nothing new will appear on this screen until they are — which usually means the queue worker is not running.
        </p>

        @can('integrations.view')
            <p class="mt-1.5 text-sm text-amber-800/80 dark:text-amber-200/70">
                Start one with <code class="rounded bg-amber-100 px-1 py-0.5 font-mono text-xs dark:bg-amber-500/20">php artisan queue:work</code>,
                or see <a href="{{ route('settings.integration-log') }}" class="font-medium text-amber-900 underline dark:text-amber-100">what has arrived</a>.
            </p>
        @endcan
    </div>
@endif
