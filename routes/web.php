<?php

use App\Domain\Settings\SettingsRegistry;
use App\Domain\Shared\Duplicates\DuplicateRegistry;
use App\Domain\Shared\Imports\ImportRegistry;
use App\Http\Controllers\ChatCaptureController;
use App\Http\Controllers\DownloadDocument;
use App\Http\Controllers\DownloadQuotePdf;
use App\Http\Controllers\EmailTrackingController;
use App\Http\Controllers\LeadCaptureController;
use App\Http\Controllers\MailWebhookController;
use App\Http\Controllers\MetaOAuthController;
use App\Http\Controllers\ShowGuide;
use App\Http\Middleware\EnsureNotInstalled;
use App\Livewire\Accounts\AccountForm;
use App\Livewire\Accounts\AccountShow;
use App\Livewire\Accounts\AccountsIndex;
use App\Livewire\Activities\ActivitiesIndex;
use App\Livewire\Activities\ActivityForm;
use App\Livewire\Approvals\ApprovalsIndex;
use App\Livewire\Audit\ActivityLogIndex;
use App\Livewire\Calendar\ActivityCalendar;
use App\Livewire\Campaigns\CampaignForm;
use App\Livewire\Campaigns\CampaignShow;
use App\Livewire\Campaigns\CampaignsIndex;
use App\Livewire\Company\CompanyProfileForm;
use App\Livewire\Contacts\ContactForm;
use App\Livewire\Contacts\ContactShow;
use App\Livewire\Contacts\ContactsIndex;
use App\Livewire\CustomFields\CustomFieldsIndex;
use App\Livewire\CustomModules\CustomModulesIndex;
use App\Livewire\CustomModules\CustomRecordForm;
use App\Livewire\CustomModules\CustomRecordsIndex;
use App\Livewire\Deals\DealForm;
use App\Livewire\Deals\DealShow;
use App\Livewire\Deals\DealsIndex;
use App\Livewire\Deals\PipelineForm;
use App\Livewire\Deals\PipelinesIndex;
use App\Livewire\Duplicates\MergeRecords;
use App\Livewire\Imports\ImportRecords;
use App\Livewire\Install\InstallWizard;
use App\Livewire\Knowledge\ArticleForm;
use App\Livewire\Knowledge\ArticleShow;
use App\Livewire\Knowledge\KnowledgeIndex;
use App\Livewire\Knowledge\KnowledgeSections;
use App\Livewire\Leads\LeadCaptureForms;
use App\Livewire\Leads\LeadConvert;
use App\Livewire\Leads\LeadForm;
use App\Livewire\Leads\LeadScoringRules;
use App\Livewire\Leads\LeadShow;
use App\Livewire\Leads\LeadsIndex;
use App\Livewire\Mail\EmailDeliveryLog;
use App\Livewire\Mail\EmailTemplates;
use App\Livewire\Meta\MetaCampaigns;
use App\Livewire\Meta\MetaConnection;
use App\Livewire\Notifications\NotificationLogIndex;
use App\Livewire\Notifications\NotificationMatrixScreen;
use App\Livewire\Notifications\NotificationTemplates;
use App\Livewire\Products\PriceBooks;
use App\Livewire\Products\ProductForm;
use App\Livewire\Products\ProductsIndex;
use App\Livewire\Profile\ProfileForm;
use App\Livewire\Reports\KpiDashboard;
use App\Livewire\Reports\ReportBuilder;
use App\Livewire\Reports\ReportSchedules;
use App\Livewire\Reports\ReportShow;
use App\Livewire\Reports\ReportsIndex;
use App\Livewire\Reports\SalesForecast;
use App\Livewire\Roles\RoleForm;
use App\Livewire\Roles\RolesIndex;
use App\Livewire\Sales\QuoteBuilder;
use App\Livewire\Sales\QuotesIndex;
use App\Livewire\Settings\ApiDocumentation;
use App\Livewire\Settings\ApiTokens;
use App\Livewire\Settings\DataSources;
use App\Livewire\Settings\IntegrationLog;
use App\Livewire\Settings\SettingsGroup;
use App\Livewire\Settings\SourceMapping;
use App\Livewire\Settings\WebhookEndpoints;
use App\Livewire\Support\SlaPolicies;
use App\Livewire\Support\SupportAnalytics;
use App\Livewire\Support\TicketForm;
use App\Livewire\Support\TicketShow;
use App\Livewire\Support\TicketsIndex;
use App\Livewire\Teams\TeamForm;
use App\Livewire\Teams\TeamsIndex;
use App\Livewire\Users\AcceptInvitation;
use App\Livewire\Users\InviteUser;
use App\Livewire\Users\UserForm;
use App\Livewire\Users\UsersIndex;
use App\Livewire\Workflows\WorkflowBuilder;
use App\Livewire\Workflows\WorkflowRunsIndex;
use App\Livewire\Workflows\WorkflowsIndex;
use Illuminate\Support\Facades\Route;

/**
 * The public lead capture form — the only unauthenticated write path here.
 *
 * Rate limited per IP, because the honeypot and the timing check are signals a
 * determined script can eventually satisfy and a throttle is the one that
 * answers before any work is done. Not in the auth group, and deliberately
 * CSRF-exempt: it is embedded in an iframe on other people's sites, where a
 * session cookie is a third-party cookie and cannot be relied on. See
 * bootstrap/app.php.
 */
Route::get('/f/{token}', [LeadCaptureController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('lead-capture.show');

Route::post('/f/{token}', [LeadCaptureController::class, 'submit'])
    ->middleware('throttle:10,1')
    ->name('lead-capture.submit');

/**
 * The guides, readable without an account.
 *
 * Public on purpose: an instruction manual behind a login is no use to somebody
 * who cannot yet work out how to log in. It renders the repository's own
 * markdown and touches neither the database nor anybody's records.
 */
Route::get('/guide', ShowGuide::class)
    ->middleware('throttle:60,1')
    ->name('guide');

/**
 * The first-run wizard. Unauthenticated by necessity — it is what creates the
 * first account — and closed for good the moment there is one, by
 * EnsureNotInstalled rather than by being unlinked.
 */
Route::get('/install', InstallWizard::class)
    ->middleware(EnsureNotInstalled::class)
    ->name('install');

Route::get('/invitations/{token}', AcceptInvitation::class)
    ->middleware('guest')
    ->name('invitations.accept');

Route::middleware('auth')->group(function () {
    Route::get('/', function () {
        return view('dashboard');
    })->name('dashboard');

    Route::get('/profile', ProfileForm::class)->name('profile');

    Route::get('/accounts', AccountsIndex::class)->name('accounts.index');
    Route::get('/accounts/create', AccountForm::class)->name('accounts.create');
    // withTrashed so a merged record's page still opens: keeping the record is
    // what makes its history survive, and the component turns away a deletion
    // that was not a merge.
    Route::get('/accounts/{account}', AccountShow::class)->withTrashed()->name('accounts.show');
    Route::get('/accounts/{account}/edit', AccountForm::class)->name('accounts.edit');

    // No show screen: an activity's detail is the form, and 3.6's calendar is
    // where they are read in context.
    Route::get('/activities', ActivitiesIndex::class)->name('activities.index');
    Route::get('/activities/create', ActivityForm::class)->name('activities.create');
    Route::get('/activities/{activity}/edit', ActivityForm::class)->name('activities.edit');

    // Its own screen rather than a fifth mode of the list: a calendar has no
    // pager and reads a window chosen on the office clock, not the stored one.
    Route::get('/calendar', ActivityCalendar::class)->name('calendar');

    // Generated modules live under /m/ so a module somebody calls "Leads"
    // cannot take over the real one's routes. The key is matched against the
    // registry inside the component, which 404s on anything unknown.
    Route::get('/m/{module}', CustomRecordsIndex::class)->name('custom-modules.index');
    Route::get('/m/{module}/create', CustomRecordForm::class)->name('custom-modules.create');
    Route::get('/m/{module}/{record}/edit', CustomRecordForm::class)->name('custom-modules.edit');

    // Marketing. A campaign is where spend meets revenue, so it is a module in
    // its own right rather than a panel on the Meta integration: the company
    // runs email, events and referral schemes too, and each has a cost.
    Route::get('/campaigns', CampaignsIndex::class)->name('campaigns.index');
    Route::get('/campaigns/create', CampaignForm::class)->name('campaigns.create');
    Route::get('/campaigns/{campaign}', CampaignShow::class)->name('campaigns.show');
    Route::get('/campaigns/{campaign}/edit', CampaignForm::class)->name('campaigns.edit');

    Route::get('/reports', ReportsIndex::class)->name('reports.index');
    // Before /reports/{report}: a dashboard is not a report id.
    Route::get('/reports/dashboard', KpiDashboard::class)->name('reports.dashboard');
    Route::get('/reports/forecast', SalesForecast::class)->name('reports.forecast');
    Route::get('/reports/scheduled', ReportSchedules::class)->name('reports.schedules');
    // Before /reports/{report}, or a report bound by id would swallow it.
    Route::get('/reports/new', ReportBuilder::class)->name('reports.create');
    Route::get('/reports/{report}', ReportShow::class)->name('reports.show');
    Route::get('/reports/{report}/edit', ReportBuilder::class)->name('reports.edit');

    Route::get('/knowledge', KnowledgeIndex::class)->name('knowledge.index');
    Route::get('/knowledge/sections', KnowledgeSections::class)->name('knowledge.sections');
    Route::get('/knowledge/new', ArticleForm::class)->name('knowledge.create');
    // Bound by slug, not id: a knowledge-base URL is pasted into replies
    // to customers and should say what it points at. 'sections' and 'new'
    // are registered above so a slug can never shadow them.
    Route::get('/knowledge/{article:slug}', ArticleShow::class)->name('knowledge.show');
    Route::get('/knowledge/{article:slug}/edit', ArticleForm::class)->name('knowledge.edit');

    Route::get('/tickets', TicketsIndex::class)->name('tickets.index');
    // Before /tickets/{ticket}, or a ticket bound by id would never be
    // reached through this path.
    Route::get('/tickets/analytics', SupportAnalytics::class)->name('tickets.analytics');
    Route::get('/tickets/create', TicketForm::class)->name('tickets.create');
    // withTrashed so a reference a customer is reading down the telephone
    // still opens a page, rather than 404ing because somebody removed it.
    Route::get('/tickets/{ticket}', TicketShow::class)->withTrashed()->name('tickets.show');
    Route::get('/tickets/{ticket}/edit', TicketForm::class)->name('tickets.edit');

    Route::get('/contacts', ContactsIndex::class)->name('contacts.index');
    Route::get('/contacts/create', ContactForm::class)->name('contacts.create');
    Route::get('/contacts/{contact}', ContactShow::class)->withTrashed()->name('contacts.show');
    Route::get('/contacts/{contact}/edit', ContactForm::class)->name('contacts.edit');

    Route::get('/deals', DealsIndex::class)->name('deals.index');
    Route::get('/deals/create', DealForm::class)->name('deals.create');
    Route::get('/deals/{deal}', DealShow::class)->withTrashed()->name('deals.show');
    Route::get('/deals/{deal}/edit', DealForm::class)->name('deals.edit');

    Route::get('/quotes', QuotesIndex::class)->name('quotes.index');
    Route::get('/quotes/create', QuoteBuilder::class)->name('quotes.create');
    Route::get('/quotes/{quote}/edit', QuoteBuilder::class)->name('quotes.edit');
    // The PDF the customer receives, rendered from the stored line figures.
    Route::get('/quotes/{quote}/pdf', DownloadQuotePdf::class)->name('quotes.pdf');

    Route::get('/products', ProductsIndex::class)->name('products.index');
    Route::get('/products/create', ProductForm::class)->name('products.create');
    Route::get('/products/{product}/edit', ProductForm::class)->name('products.edit');

    Route::get('/leads', LeadsIndex::class)->name('leads.index');
    Route::get('/leads/create', LeadForm::class)->name('leads.create');
    Route::get('/leads/{lead}', LeadShow::class)->withTrashed()->name('leads.show');
    Route::get('/leads/{lead}/edit', LeadForm::class)->name('leads.edit');
    Route::get('/leads/{lead}/convert', LeadConvert::class)->name('leads.convert');

    // Automations. The builder is its own page rather than a settings panel:
    // it edits a trigger, a condition tree and a list of steps together, which
    // is more than a settings group's two columns can hold.
    // Not permission-gated: being an approver is an instruction from a
    // workflow to a named person, and the screen scopes itself to them.
    Route::get('/approvals', ApprovalsIndex::class)->name('approvals.index');

    Route::get('/workflows', WorkflowsIndex::class)->name('workflows.index');
    // Before /workflows/{workflow}/edit, so "log" is never taken for an id.
    Route::get('/workflows/log', WorkflowRunsIndex::class)->name('workflows.log');
    Route::get('/workflows/create', WorkflowBuilder::class)->name('workflows.create');
    Route::get('/workflows/{workflow}/edit', WorkflowBuilder::class)->name('workflows.edit');

    // One import screen for every module in ImportRegistry, resolved the same
    // way: an unlisted module 404s rather than becoming a class name.
    Route::get('/import/{module}', ImportRecords::class)
        ->whereIn('module', ImportRegistry::keys())
        ->name('imports.create');

    // One merge screen for every module in DuplicateRegistry. The {module}
    // segment is matched against the registry here and again in the component,
    // so it can never become an arbitrary class name.
    Route::get('/duplicates/{module}/{record}/merge', MergeRecords::class)
        ->whereIn('module', DuplicateRegistry::keys())
        ->whereNumber('record')
        ->name('duplicates.merge');

    // Document bytes live on the private disk, so the only way to them is
    // through here, and the policy is asked before a single byte is streamed.
    Route::get('/documents/{document}/download', DownloadDocument::class)
        ->name('documents.download');

    Route::get('/settings/company', CompanyProfileForm::class)->name('settings.company');

    // The Meta connection. Its own screen rather than a settings group: the
    // group holds the app credentials, while this holds what those credentials
    // have been used to connect — a different question with a different answer
    // every time somebody looks.
    Route::get('/settings/meta/connect', MetaConnection::class)->name('settings.meta.connect');
    // POST, because starting the flow writes a state token into the session and
    // a GET that changes state is one a browser can be made to make.
    Route::post('/settings/meta/connect', [MetaOAuthController::class, 'redirect'])->name('settings.meta.redirect');
    Route::get('/settings/meta/callback', [MetaOAuthController::class, 'callback'])->name('settings.meta.callback');

    // What the advertising is, and which CRM campaign each part of it belongs
    // to. Beside the connection rather than under Campaigns: the decision it
    // exists for is about an integration, and the person who makes it is the
    // one who set Meta up.
    Route::get('/settings/meta/campaigns', MetaCampaigns::class)->name('settings.meta.campaigns');

    Route::get('/settings/users', UsersIndex::class)->name('settings.users');
    Route::get('/settings/users/create', UserForm::class)->name('settings.users.create');
    Route::get('/settings/users/invite', InviteUser::class)->name('settings.users.invite');
    Route::get('/settings/users/{user}/edit', UserForm::class)->name('settings.users.edit');

    Route::get('/settings/teams', TeamsIndex::class)->name('settings.teams');
    Route::get('/settings/teams/create', TeamForm::class)->name('settings.teams.create');
    Route::get('/settings/teams/{team}/edit', TeamForm::class)->name('settings.teams.edit');

    Route::get('/settings/roles', RolesIndex::class)->name('settings.roles');
    Route::get('/settings/roles/create', RoleForm::class)->name('settings.roles.create');
    Route::get('/settings/roles/{role}/edit', RoleForm::class)->name('settings.roles.edit');

    Route::get('/settings/lead-scoring', LeadScoringRules::class)->name('settings.lead-scoring');
    Route::get('/settings/lead-forms', LeadCaptureForms::class)->name('settings.lead-forms');

    Route::get('/settings/custom-fields', CustomFieldsIndex::class)->name('settings.custom-fields');
    Route::get('/settings/price-books', PriceBooks::class)->name('settings.price-books');
    Route::get('/settings/custom-modules', CustomModulesIndex::class)->name('settings.custom-modules');

    Route::get('/settings/pipelines', PipelinesIndex::class)->name('settings.pipelines');
    Route::get('/settings/pipelines/create', PipelineForm::class)->name('settings.pipelines.create');
    Route::get('/settings/pipelines/{pipeline}/edit', PipelineForm::class)->name('settings.pipelines.edit');

    Route::get('/settings/sla-policies', SlaPolicies::class)->name('settings.sla-policies');

    Route::get('/settings/audit-log', ActivityLogIndex::class)->name('settings.audit');

    // Deliberately not /settings/mail: that path is the email provider settings
    // group, and a dedicated route there would shadow it.
    Route::get('/settings/email-delivery', EmailDeliveryLog::class)->name('settings.mail-log');
    Route::get('/settings/email-templates', EmailTemplates::class)->name('settings.email-templates');
    Route::get('/settings/data-sources', DataSources::class)->name('settings.data-sources');
    Route::get('/settings/data-sources/{source}/mapping', SourceMapping::class)->name('settings.data-sources.mapping');
    Route::get('/settings/delivery-log', IntegrationLog::class)->name('settings.integration-log');
    Route::get('/settings/api-keys', ApiTokens::class)->name('settings.api-tokens');
    Route::get('/settings/webhooks', WebhookEndpoints::class)->name('settings.webhooks');
    Route::get('/settings/api-documentation', ApiDocumentation::class)->name('settings.api-docs');

    // Deliberately not /settings/notifications: that path belongs to the
    // settings registry group of the same name (quiet hours and limits), and
    // a route registered here would shadow it entirely.
    Route::get('/settings/notification-rules', NotificationMatrixScreen::class)->name('settings.notifications');
    Route::get('/settings/notification-rules/templates', NotificationTemplates::class)->name('settings.notifications.templates');
    Route::get('/settings/notification-rules/log', NotificationLogIndex::class)->name('settings.notifications.log');

    // Registry-backed groups. The {group} segment is matched against the
    // registry in the component, which 404s on anything undeclared.
    Route::get('/settings/{group}', SettingsGroup::class)
        ->whereIn('group', SettingsRegistry::groupKeys())
        ->name('settings.group');
});

/**
 * Provider webhooks. Outside every auth group by necessity — the caller is
 * Mailgun, not a person — and guarded by the token in the path plus the
 * provider's own signature where there is one. See MailWebhookController.
 */
Route::post('/webhooks/mail/{provider}/{token}', MailWebhookController::class)
    ->name('webhooks.mail');

/**
 * Open and click tracking. Signed rather than authenticated — the caller is a
 * customer's mail client — and the signature is what stops the click route
 * being an open redirect. See EmailTrackingController.
 */
Route::get('/e/o/{tracking}', [EmailTrackingController::class, 'open'])
    ->middleware('signed')
    ->name('mail.track.open');

Route::get('/e/c/{tracking}', [EmailTrackingController::class, 'click'])
    ->middleware('signed')
    ->name('mail.track.click');

/**
 * The website chat widget. Public, cross-site, and guarded the same way the
 * capture form is: an unguessable token, a rate limit, and nothing in the
 * payload that can name an owner. See ChatCaptureController.
 */
Route::post('/c/{token}', ChatCaptureController::class)
    ->middleware('throttle:30,1')
    ->name('chat.message');
