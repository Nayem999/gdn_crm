<x-layouts.app title="Dashboard">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-foreground">Dashboard</h1>
        <p class="mt-1 text-sm text-muted-foreground">Welcome back. Your CRM modules will appear here as they're built.</p>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach (['Leads', 'Deals', 'Open Tickets', 'Revenue This Month'] as $label)
            <div class="rounded-xl border border-border bg-card p-5 shadow-sm">
                <p class="text-sm font-medium text-muted-foreground">{{ $label }}</p>
                <p class="mt-2 text-2xl font-semibold text-card-foreground">&mdash;</p>
            </div>
        @endforeach
    </div>

    <div class="mt-6 rounded-xl border border-dashed border-border p-10 text-center">
        <p class="text-sm text-muted-foreground">Modules ship phase by phase. Check back once Leads, Deals, and Activities land.</p>
    </div>
</x-layouts.app>
