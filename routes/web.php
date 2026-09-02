<?php

use App\Livewire\Audit\ActivityLogIndex;
use App\Livewire\Company\CompanyProfileForm;
use App\Livewire\Profile\ProfileForm;
use App\Livewire\Roles\RoleForm;
use App\Livewire\Roles\RolesIndex;
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

    Route::get('/settings/audit-log', ActivityLogIndex::class)->name('settings.audit');
});
