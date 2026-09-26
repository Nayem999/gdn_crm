{{--
    Never the exception's message: for a missing record Laravel's reads "No query
    results for model [App\Domain\Leads\Models\Lead] 152", which names the
    application's classes to whoever typed the URL.
--}}
<x-error-page
    code="404"
    title="Page not found"
    message="Not Found."
    icon="lucide-search-x"
>
    The page you asked for does not exist, or the record it showed has been
    removed. Check the address, or start again from the dashboard.
</x-error-page>
