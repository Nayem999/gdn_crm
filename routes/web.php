<?php

use App\Livewire\Company\CompanyProfileForm;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/', function () {
        return view('dashboard');
    })->name('dashboard');

    Route::get('/settings/company', CompanyProfileForm::class)->name('settings.company');
});
