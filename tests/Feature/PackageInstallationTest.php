<?php

use Barryvdh\DomPDF\ServiceProvider as DomPdfServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Laravel\Fortify\FortifyServiceProvider;
use Laravel\Horizon\HorizonServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;
use Livewire\LivewireServiceProvider;
use Maatwebsite\Excel\ExcelServiceProvider;
use MallardDuck\LucideIcons\BladeLucideIconsServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

it('registers a service provider for every stack package', function (string $provider) {
    expect(app()->getLoadedProviders())->toHaveKey($provider);
})->with([
    LivewireServiceProvider::class,
    FortifyServiceProvider::class,
    SanctumServiceProvider::class,
    HorizonServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    PermissionServiceProvider::class,
    ActivitylogServiceProvider::class,
    MediaLibraryServiceProvider::class,
    ExcelServiceProvider::class,
    DomPdfServiceProvider::class,
    BladeIconsServiceProvider::class,
    BladeLucideIconsServiceProvider::class,
]);

it('publishes a readable config file for every stack package', function (string $key) {
    expect(config($key))->toBeArray()->not->toBeEmpty();
})->with([
    'livewire',
    'fortify',
    'sanctum',
    'horizon',
    'permission',
    'activitylog',
    'media-library',
    'excel',
    'dompdf',
    'blade-icons',
]);

test('sanctum personal access tokens table migration is present', function () {
    expect(file_exists(database_path('migrations')))->toBeTrue();

    $migrations = collect(glob(database_path('migrations/*.php')))
        ->map(fn (string $path) => basename($path));

    expect($migrations->filter(fn (string $name) => str_contains($name, 'create_personal_access_tokens_table')))->not->toBeEmpty()
        ->and($migrations->filter(fn (string $name) => str_contains($name, 'create_permission_tables')))->not->toBeEmpty()
        ->and($migrations->filter(fn (string $name) => str_contains($name, 'create_activity_log_table')))->not->toBeEmpty()
        ->and($migrations->filter(fn (string $name) => str_contains($name, 'create_media_table')))->not->toBeEmpty();
});
