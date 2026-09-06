<?php

use App\Domain\Settings\SettingsRegistry;
use App\Domain\Shared\Duplicates\DuplicateRegistry;
use App\Domain\Shared\Imports\ImportRegistry;
use App\Http\Controllers\DownloadDocument;
use App\Livewire\Accounts\AccountForm;
use App\Livewire\Accounts\AccountShow;
use App\Livewire\Accounts\AccountsIndex;
use App\Livewire\Audit\ActivityLogIndex;
use App\Livewire\Company\CompanyProfileForm;
use App\Livewire\Contacts\ContactForm;
use App\Livewire\Contacts\ContactShow;
use App\Livewire\Contacts\ContactsIndex;
use App\Livewire\Deals\PipelineForm;
use App\Livewire\Deals\PipelinesIndex;
use App\Livewire\Duplicates\MergeRecords;
use App\Livewire\Imports\ImportRecords;
use App\Livewire\Leads\LeadConvert;
use App\Livewire\Leads\LeadForm;
use App\Livewire\Leads\LeadScoringRules;
use App\Livewire\Leads\LeadShow;
use App\Livewire\Leads\LeadsIndex;
use App\Livewire\Notifications\NotificationLogIndex;
use App\Livewire\Notifications\NotificationMatrixScreen;
use App\Livewire\Notifications\NotificationTemplates;
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
use Illuminate\Support\Facades\Route;

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

    Route::get('/contacts', ContactsIndex::class)->name('contacts.index');
    Route::get('/contacts/create', ContactForm::class)->name('contacts.create');
    Route::get('/contacts/{contact}', ContactShow::class)->withTrashed()->name('contacts.show');
    Route::get('/contacts/{contact}/edit', ContactForm::class)->name('contacts.edit');

    Route::get('/leads', LeadsIndex::class)->name('leads.index');
    Route::get('/leads/create', LeadForm::class)->name('leads.create');
    Route::get('/leads/{lead}', LeadShow::class)->withTrashed()->name('leads.show');
    Route::get('/leads/{lead}/edit', LeadForm::class)->name('leads.edit');
    Route::get('/leads/{lead}/convert', LeadConvert::class)->name('leads.convert');

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
