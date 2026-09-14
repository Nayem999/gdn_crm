<?php

namespace App\Providers;

use App\Domain\Access\Policies\RolePolicy;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Policies\AccountPolicy;
use App\Domain\Activities\Models\Activity;
use App\Domain\Activities\Policies\ActivityPolicy;
use App\Domain\Api\ApiModules;
use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Approvals\Policies\ApprovalRequestPolicy;
use App\Domain\Audit\Policies\AuditEntryPolicy;
use App\Domain\Auth\Listeners\RecordLoginHistory;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Policies\CompanyPolicy;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Policies\ContactPolicy;
use App\Domain\CustomFields\CustomFieldSchema;
use App\Domain\CustomFields\Models\CustomField;
use App\Domain\CustomFields\Policies\CustomFieldPolicy;
use App\Domain\CustomModules\CustomModuleRegistry;
use App\Domain\CustomModules\Models\CustomModule;
use App\Domain\CustomModules\Models\CustomRecord;
use App\Domain\CustomModules\Policies\CustomModulePolicy;
use App\Domain\CustomModules\Policies\CustomRecordPolicy;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\Pipeline;
use App\Domain\Deals\PipelineStatusCache;
use App\Domain\Deals\Policies\DealPolicy;
use App\Domain\Deals\Policies\PipelinePolicy;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Policies\DataSourcePolicy;
use App\Domain\Knowledge\Models\Article;
use App\Domain\Knowledge\Models\Category;
use App\Domain\Knowledge\Policies\ArticlePolicy;
use App\Domain\Knowledge\Policies\CategoryPolicy;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadCaptureForm;
use App\Domain\Leads\Models\LeadScoringRule;
use App\Domain\Leads\Policies\LeadCaptureFormPolicy;
use App\Domain\Leads\Policies\LeadPolicy;
use App\Domain\Leads\Policies\LeadScoringRulePolicy;
use App\Domain\Mail\Inbound\ImapMailbox;
use App\Domain\Mail\Inbound\InboundMailbox;
use App\Domain\Mail\Inbound\InboundMailConfiguration;
use App\Domain\Mail\Listeners\RecordSentEmail;
use App\Domain\Mail\MailConfiguration;
use App\Domain\Mail\Models\EmailMessage;
use App\Domain\Mail\Models\EmailTemplate;
use App\Domain\Mail\Policies\EmailMessagePolicy;
use App\Domain\Mail\Policies\EmailTemplatePolicy;
use App\Domain\Mail\Transports\ManagedTransport;
use App\Domain\Messaging\MessagingConfiguration;
use App\Domain\Notifications\ChannelManager;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\NotificationMatrix;
use App\Domain\Notifications\Policies\NotificationPolicy;
use App\Domain\Products\Models\PriceBook;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Policies\PriceBookPolicy;
use App\Domain\Products\Policies\ProductPolicy;
use App\Domain\Reports\Models\Report;
use App\Domain\Reports\Models\ReportSchedule;
use App\Domain\Reports\Policies\ReportPolicy;
use App\Domain\Reports\Policies\ReportSchedulePolicy;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Policies\QuotePolicy;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\Policies\SettingPolicy;
use App\Domain\Settings\SettingsManager;
use App\Domain\Shared\RequestMemo;
use App\Domain\Support\Models\SlaPolicy;
use App\Domain\Support\Models\Ticket;
use App\Domain\Support\Models\TicketComment;
use App\Domain\Support\Policies\SlaPolicyPolicy;
use App\Domain\Support\Policies\TicketCommentPolicy;
use App\Domain\Support\Policies\TicketPolicy;
use App\Domain\Teams\Policies\TeamPolicy;
use App\Domain\Timeline\Models\Document;
use App\Domain\Timeline\Models\Note;
use App\Domain\Timeline\Policies\DocumentPolicy;
use App\Domain\Timeline\Policies\NotePolicy;
use App\Domain\Users\Policies\UserPolicy;
use App\Domain\Webhooks\Models\WebhookEndpoint;
use App\Domain\Webhooks\Policies\WebhookEndpointPolicy;
use App\Domain\Webhooks\WebhookObserver;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Policies\WorkflowPolicy;
use App\Domain\Workflows\Triggers\WorkflowObserver;
use App\Domain\Workflows\Triggers\WorkflowSuppressor;
use App\Domain\Workflows\WorkflowCache;
use App\Domain\Workflows\WorkflowModules;
use App\Listeners\VerifyApplicationHealth;
use App\Models\Team;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity as AuditEntry;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One memo per container: per web request, per queued job, per test.
        // Anything that resolves it mid-request shares the same instance,
        // which is the whole point.
        $this->app->singleton(RequestMemo::class);

        // One manager per request, so its per-request cache of loaded groups is
        // shared by everything that reads a setting during that request.
        $this->app->singleton(SettingsManager::class);
        // One matrix and one channel manager per request: both hold a resolved
        // cache, and a test that swaps in a recording driver needs the same
        // instance the dispatcher uses.
        $this->app->singleton(NotificationMatrix::class);
        $this->app->singleton(ChannelManager::class);
        // One memo of the custom field definitions per request, flushed by the
        // actions that change one. See CustomFieldSchema.
        $this->app->singleton(CustomFieldSchema::class);
        // Same arrangement for the configured status sets — see PipelineStatusCache.
        $this->app->singleton(PipelineStatusCache::class);
        // And the generated module definitions — see CustomModuleRegistry.
        $this->app->singleton(CustomModuleRegistry::class);

        // The listening set is asked for on every save of every record, and the
        // suppressor's depth has to be one value for the whole request — a
        // fresh instance per resolution would mean an action's writes were
        // suppressed for nobody.
        $this->app->singleton(WorkflowCache::class);
        $this->app->singleton(WorkflowSuppressor::class);

        // One managed transport for the process: it holds the built provider
        // transports, keyed by their credentials, so a run of messages reuses
        // one SMTP connection while a changed credential still rebuilds.
        $this->app->singleton(MailConfiguration::class);
        $this->app->singleton(MessagingConfiguration::class);
        $this->app->singleton(ManagedTransport::class);

        // Bound rather than newed at the call site so a test can hand the sync
        // a mailbox that needs no mail server. Not a singleton: a mailbox holds
        // an open connection, and one per resolution is the honest lifetime.
        $this->app->bind(
            InboundMailbox::class,
            fn ($app) => new ImapMailbox($app->make(InboundMailConfiguration::class)->mailboxSettings())
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Team::class, TeamPolicy::class);
        // Super Admin gets its way by holding every permission (kept in sync by
        // RolesAndPermissionsSeeder) rather than a Gate::before bypass, so
        // business rules such as "you cannot delete your own account" still hold.
        Gate::policy(Role::class, RolePolicy::class);
        // Two different things called Activity: the audit entry and the CRM
        // record. Aliased at the import so the pairing here is unambiguous.
        Gate::policy(AuditEntry::class, AuditEntryPolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);
        Gate::policy(DataSource::class, DataSourcePolicy::class);
        Gate::policy(Ticket::class, TicketPolicy::class);
        Gate::policy(TicketComment::class, TicketCommentPolicy::class);
        Gate::policy(SlaPolicy::class, SlaPolicyPolicy::class);
        Gate::policy(Report::class, ReportPolicy::class);
        Gate::policy(ReportSchedule::class, ReportSchedulePolicy::class);
        Gate::policy(Article::class, ArticlePolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Account::class, AccountPolicy::class);
        Gate::policy(Contact::class, ContactPolicy::class);
        Gate::policy(Lead::class, LeadPolicy::class);
        Gate::policy(Deal::class, DealPolicy::class);
        Gate::policy(Pipeline::class, PipelinePolicy::class);
        Gate::policy(CustomField::class, CustomFieldPolicy::class);
        Gate::policy(CustomModule::class, CustomModulePolicy::class);
        Gate::policy(CustomRecord::class, CustomRecordPolicy::class);
        Gate::policy(LeadScoringRule::class, LeadScoringRulePolicy::class);
        Gate::policy(LeadCaptureForm::class, LeadCaptureFormPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(PriceBook::class, PriceBookPolicy::class);
        Gate::policy(Quote::class, QuotePolicy::class);
        Gate::policy(Workflow::class, WorkflowPolicy::class);
        Gate::policy(ApprovalRequest::class, ApprovalRequestPolicy::class);
        Gate::policy(Setting::class, SettingPolicy::class);
        Gate::policy(NotificationLog::class, NotificationPolicy::class);
        Gate::policy(EmailMessage::class, EmailMessagePolicy::class);
        Gate::policy(EmailTemplate::class, EmailTemplatePolicy::class);
        Gate::policy(WebhookEndpoint::class, WebhookEndpointPolicy::class);
        // Both ask the subject record's own policy before answering, so a note
        // or an attachment is never a way round a module's access level.
        Gate::policy(Note::class, NotePolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);

        // Every module a workflow can watch, from the registry's own list, so a
        // module cannot be observed without being one. CustomRecord is named
        // separately because it is the single class behind every generated
        // module rather than a module of its own.
        foreach ([...array_column(WorkflowModules::builtIn(), 'model'), CustomRecord::class] as $watched) {
            $watched::observe(WorkflowObserver::class);
        }

        // Outbound webhooks watch the modules the REST API publishes, which is
        // a shorter list on purpose: an event key is part of a promise to
        // somebody else's system, and the payload is the API's own shape.
        foreach (ApiModules::keys() as $published) {
            $module = ApiModules::find($published);

            if ($module !== null) {
                $module->modelClass()::observe(WebhookObserver::class);
            }
        }

        // The API's rate limit, keyed by the **token** rather than the person.
        // Two integrations owned by the same administrator should not be able
        // to starve each other, and revoking one should not carry the other's
        // history with it.
        RateLimiter::for('api', function (Request $request) {
            $perMinute = (int) settings('api.rate_limit_per_minute', 60);

            // Keyed off the bearer token itself rather than $request->user():
            // the throttle runs before authentication in Laravel's middleware
            // order, so asking for the user here gets null and every key in the
            // organisation shares one bucket keyed by IP. Hashed, so the key
            // never reaches the cache store in plain text.
            $bearer = $request->bearerToken();

            return Limit::perMinute(max(1, $perMinute))->by(
                $bearer === null || $bearer === ''
                    ? 'ip-'.$request->ip()
                    : 'key-'.hash('sha256', $bearer)
            );
        });

        // The ingest endpoint's limit, keyed by **source** rather than by
        // address. One integration behind a busy proxy must not be able to
        // starve another, and a source is the thing an administrator can switch
        // off when one misbehaves. Unknown sources share an address bucket, so
        // somebody spraying invented uuids is throttled as one caller.
        RateLimiter::for('ingest', function (Request $request) {
            $perMinute = (int) settings('integrations.ingest_rate_limit_per_minute', 120);
            $source = (string) $request->route('source');

            return Limit::perMinute(max(1, $perMinute))->by(
                $source === '' ? 'ingest-ip-'.$request->ip() : 'ingest-'.$source
            );
        });

        Event::subscribe(RecordLoginHistory::class);

        // Every message that goes out through Laravel's mailer gets a row in
        // the delivery log. Deliberately not in the transport: the transport is
        // also how Test Connection and a raw send reach a provider, and neither
        // belongs in the log.
        // Laravel's /up answers 200 as long as the framework booted, which a
        // monitor reads as "everything is fine" while the database is
        // unreachable. This makes it mean what a monitor assumes it means.
        Event::listen(DiagnosingHealth::class, VerifyApplicationHealth::class);

        Event::listen(MessageSent::class, RecordSentEmail::class);

        // The "crm" mailer. The transport reads the configured provider on
        // every send, so this closure runs once and stays correct — including
        // in a queue worker, where a mailer resolved at boot would otherwise
        // keep the provider it started with.
        Mail::extend('crm', fn (): ManagedTransport => $this->app->make(ManagedTransport::class));

        // Models live under app/Domain/{Module}/Models, which Laravel's default
        // resolver would map to Database\Factories\Domain\{Module}\Models\...
        // All factories live flat in Database\Factories instead.
        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }
}
