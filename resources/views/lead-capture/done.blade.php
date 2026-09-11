<x-lead-capture.layout :form="$form" title="Thank you">
    <div class="rounded-xl border border-border bg-card p-8 text-center">
        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300">
            <x-icon name="lucide-check" class="h-6 w-6" />
        </div>

        <h1 class="mt-4 text-lg font-semibold text-foreground">Thank you</h1>

        <p class="mt-1 text-sm text-muted-foreground">
            {{ $form->success_message ?? 'We have your details and will be in touch.' }}
        </p>
    </div>
</x-lead-capture.layout>
