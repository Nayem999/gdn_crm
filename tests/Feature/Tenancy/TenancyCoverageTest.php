<?php

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Tenancy is not optional, and this is what makes that true
|--------------------------------------------------------------------------
|
| With a database per customer, isolation is a fact of the schema. This
| installation shares one database, so isolation is a property of the code —
| which means it can be forgotten, by me next week or by whoever adds the next
| model. The whole bet rests on that not happening silently.
|
| So the list below shrinks as modules move across, and nothing may be added
| to it. A model that gains tenancy without a column, a table with a column
| nobody scopes, a new model that is neither — each fails here rather than in
| production, quietly, as one customer reading another's pipeline.
|
*/

/**
 * Tables that carry no tenant, and why.
 *
 * Framework plumbing, the tenant list itself, and rows that belong to the
 * installation rather than to any one customer. Everything else is either
 * already scoped or still on the list below it.
 *
 * @var array<int, string>
 */
const TENANCY_GLOBAL_TABLES = [
    // Laravel's own.
    'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
    'sessions', 'password_reset_tokens',
    // The workspaces themselves, and the people in them: a user is looked up
    // by email before any workspace is known, so users carry a tenant_id and
    // deliberately no global scope. See BelongsToTenant.
    'tenants', 'users',
];

/**
 * Modules not yet moved across.
 *
 * **This list only ever gets shorter.** It is the honest record of how far
 * the migration has got, kept in the test rather than in somebody's head, so
 * that "is tenancy finished" has an answer a machine can give.
 *
 * @var array<int, string>
 */
const TENANCY_PENDING_TABLES = [
    'accounts', 'activities', 'activity_log', 'approval_levels', 'approval_requests',
    'assignment_pointers', 'campaigns', 'chat_conversations', 'chat_messages', 'companies', 'contacts',
    'custom_field_values', 'custom_fields', 'custom_modules', 'custom_records', 'dashboard_widgets',
    'data_source_filters', 'data_source_mappings', 'data_sources', 'deal_stage_entries', 'deals',
    'document_lines', 'document_sequences', 'documents', 'duplicate_keys', 'email_events',
    'email_messages', 'email_templates', 'import_runs', 'inbound_messages', 'integration_events',
    'invoices', 'kb_articles', 'kb_categories', 'lead_capture_forms', 'lead_scoring_rules',
    'login_histories', 'marketing_attributions', 'media', 'meta_accounts', 'meta_ad_accounts',
    'meta_ad_sets', 'meta_ads', 'meta_campaigns', 'meta_conversion_events', 'meta_forms',
    'meta_insights', 'meta_leads', 'meta_pages', 'model_has_permissions', 'model_has_roles', 'notes',
    'notification_logs', 'notification_preferences', 'notification_settings', 'notification_templates',
    'notifications', 'payments', 'permissions', 'personal_access_tokens', 'pipeline_stages',
    'pipelines', 'price_book_entries', 'price_books', 'price_breaks', 'product_bundle_items',
    'products', 'purchase_orders', 'quotes', 'report_schedules', 'reports', 'role_has_permissions',
    'roles', 'sales_orders', 'saved_views', 'settings', 'sla_policies', 'sla_targets',
    'social_conversations', 'social_messages', 'team_user', 'teams', 'ticket_comments',
    'ticket_watchers', 'tickets', 'user_invitations', 'user_view_preferences', 'webhook_deliveries',
    'webhook_endpoints', 'whatsapp_business_accounts', 'whatsapp_phone_numbers', 'whatsapp_templates',
    'workflow_actions', 'workflow_run_steps', 'workflow_runs', 'workflows',
];

function tenancyTables(): array
{
    // This database, not this server. getTableListing() answers for every
    // schema the connection can see, and on a shared MySQL that is every other
    // application on the machine — which made the first version of this sweep
    // assert things about somebody else's tables.
    $database = DB::connection()->getDatabaseName();

    return collect(Schema::getTables())
        ->where('schema', $database)
        ->pluck('name')
        ->all();
}

/**
 * Every Eloquent model under app/Domain and app/Models.
 *
 * @return array<int, class-string<Model>>
 */
function tenancyModels(): array
{
    $models = [];

    foreach (Finder::create()->files()->in([app_path('Domain'), app_path('Models')])->name('*.php') as $file) {
        $class = 'App\\'.Str::of($file->getRealPath())
            ->after(app_path().DIRECTORY_SEPARATOR)
            ->before('.php')
            ->replace(DIRECTORY_SEPARATOR, '\\')
            ->toString();

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            continue;
        }

        $models[] = $class;
    }

    return $models;
}

test('every model that claims tenancy has somewhere to put it', function () {
    foreach (tenancyModels() as $class) {
        if (! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
            continue;
        }

        $table = (new $class)->getTable();

        expect(Schema::hasColumn($table, 'tenant_id'))->toBeTrue();
    }
});

test('every table with a tenant column has a model that scopes it', function () {
    $scoped = [];

    foreach (tenancyModels() as $class) {
        if (in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
            $scoped[] = (new $class)->getTable();
        }
    }

    foreach (tenancyTables() as $table) {
        if (! Schema::hasColumn($table, 'tenant_id') || in_array($table, TENANCY_GLOBAL_TABLES, true)) {
            continue;
        }

        // A column nobody scopes is worse than no column: it looks finished.
        expect($scoped)->toContain($table);
    }
});

test('the tables still to be moved are the ones we think they are', function () {
    $tables = array_values(array_filter(
        tenancyTables(),
        fn (string $table): bool => ! in_array($table, TENANCY_GLOBAL_TABLES, true)
            && ! Schema::hasColumn($table, 'tenant_id'),
    ));

    sort($tables);
    $pending = TENANCY_PENDING_TABLES;
    sort($pending);

    // Fails in both directions on purpose. A new untenanted table has to be
    // named here, which makes adding one a decision rather than an oversight;
    // and a table that has been moved has to come off, so the list cannot
    // quietly stop meaning anything.
    expect($tables)->toBe($pending);
});
