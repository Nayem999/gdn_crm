<?php

use App\Livewire\Company\CompanyProfileForm;
use App\Livewire\Profile\ProfileForm;
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
});
