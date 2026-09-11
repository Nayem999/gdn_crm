<x-layouts.app title="Dashboard">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-foreground">Dashboard</h1>
        <p class="mt-1 text-sm text-muted-foreground">
            {{ \App\Domain\Settings\DisplayTime::now()->format('l j F') }} &mdash; here is where things stand.
        </p>
    </div>

    {{-- Every widget is lazy and isolated, so four independent aggregates load
         in parallel behind their own skeletons rather than holding up the page
         between them. Each one applies the viewer's permissions and access
         level itself; there is no shared "dashboard data" the page hands down. --}}
    <livewire:dashboard.dashboard-kpi-cards lazy />

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <livewire:dashboard.dashboard-funnel lazy />
            <livewire:dashboard.dashboard-activity-feed lazy />
        </div>

        <div class="lg:col-span-1">
            <livewire:dashboard.dashboard-tasks lazy />
        </div>
    </div>
</x-layouts.app>
