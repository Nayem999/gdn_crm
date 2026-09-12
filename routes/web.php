<?php

use App\Domain\Settings\SettingsRegistry;
use App\Domain\Shared\Duplicates\DuplicateRegistry;
use App\Domain\Shared\Imports\ImportRegistry;
use App\Http\Controllers\DownloadDocument;
use App\Http\Controllers\LeadCaptureController;
use App\Livewire\Accounts\AccountForm;
use App\Livewire\Accounts\AccountShow;
use App\Livewire\Accounts\AccountsIndex;
use App\Livewire\Activities\ActivitiesIndex;
use App\Livewire\Activities\ActivityForm;
use App\Livewire\Approvals\ApprovalsIndex;
use App\Livewire\Audit\ActivityLogIndex;
use App\Livewire\Calendar\ActivityCalendar;
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
use App\Livewire\Leads\LeadCaptureForms;
use App\Livewire\Leads\LeadConvert;
use App\Livewire\Leads\LeadForm;
use App\Livewire\Leads\LeadScoringRules;
use App\Livewire\Leads\LeadShow;
use App\Livewire\Leads\LeadsIndex;
use App\Livewire\Notifications\NotificationLogIndex;
use App\Livewire\Notifications\NotificationMatrixScreen;
use App\Livewire\Notifications\NotificationTemplates;
use App\Livewire\Products\PriceBooks;
use App\Livewire\Products\ProductForm;
use App\Livewire\Products\ProductsIndex;
use App\Livewire\Profile\ProfileForm;
use App\Livewire\Roles\RoleForm;
use App\Livewire\Roles\RolesIndex;
use App\Livewire\Settings\SettingsGroup;
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

    Route::get('/contacts', ContactsIndex::class)->name('contacts.index');
    Route::get('/contacts/create', ContactForm::class)->name('contacts.create');
    Route::get('/contacts/{contact}', ContactShow::class)->withTrashed()->name('contacts.show');
    Route::get('/contacts/{contact}/edit', ContactForm::class)->name('contacts.edit');

    Route::get('/deals', DealsIndex::class)->name('deals.index');
    Route::get('/deals/create', DealForm::class)->name('deals.create');
    Route::get('/deals/{deal}', DealShow::class)->withTrashed()->name('deals.show');
    Route::get('/deals/{deal}/edit', DealForm::class)->name('deals.edit');

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

    Route::get('/settings/audit-log', ActivityLogIndex::class)->name('settings.audit');

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
