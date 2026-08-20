<?php

use App\Domain\Company\Models\Company;
use App\Livewire\Company\CompanyProfileForm;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

function userWithCompanyPermissions(array $permissions = ['company.view', 'company.update']): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission));
    }

    return $user;
}

test('the company profile is a single record created on first access', function () {
    expect(Company::query()->count())->toBe(0);

    $first = Company::current();
    $second = Company::current();

    expect(Company::query()->count())->toBe(1)
        ->and($first->id)->toBe($second->id);
});

test('guests are redirected to login when visiting the company settings page', function () {
    $this->get(route('settings.company'))->assertRedirect(route('login'));
});

test('a user without the company.view permission cannot mount the form', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(CompanyProfileForm::class)->assertForbidden();
});

test('an authorized user sees the current company profile values', function () {
    $company = Company::current();
    $company->update(['name' => 'Golden Info Tech', 'timezone' => 'Asia/Dhaka', 'currency' => 'BDT']);

    $this->actingAs(userWithCompanyPermissions());

    Livewire::test(CompanyProfileForm::class)
        ->assertSet('name', 'Golden Info Tech')
        ->assertSet('timezone', 'Asia/Dhaka')
        ->assertSet('currency', 'BDT');
});

test('an authorized user can save changes to the company profile', function () {
    $this->actingAs(userWithCompanyPermissions());

    Livewire::test(CompanyProfileForm::class)
        ->set('name', 'Updated Company Name')
        ->set('addressLine1', '123 Main St')
        ->set('city', 'Dhaka')
        ->set('country', 'Bangladesh')
        ->set('timezone', 'Asia/Dhaka')
        ->set('currency', 'bdt')
        ->set('fiscalYearStartMonth', 7)
        ->call('save')
        ->assertHasNoErrors();

    $company = Company::current();

    expect($company->name)->toBe('Updated Company Name')
        ->and($company->address_line_1)->toBe('123 Main St')
        ->and($company->city)->toBe('Dhaka')
        ->and($company->timezone)->toBe('Asia/Dhaka')
        ->and($company->currency)->toBe('BDT')
        ->and($company->fiscal_year_start_month)->toBe(7);
});

test('a user without the company.update permission cannot save changes', function () {
    $this->actingAs(userWithCompanyPermissions(['company.view']));

    Livewire::test(CompanyProfileForm::class)
        ->set('name', 'Should Not Save')
        ->call('save')
        ->assertForbidden();

    expect(Company::current()->name)->not->toBe('Should Not Save');
});

test('the company profile requires a name, valid timezone, currency and fiscal month', function () {
    $this->actingAs(userWithCompanyPermissions());

    Livewire::test(CompanyProfileForm::class)
        ->set('name', '')
        ->set('timezone', 'Not/A_Timezone')
        ->set('currency', 'US')
        ->set('fiscalYearStartMonth', 13)
        ->call('save')
        ->assertHasErrors(['name' => 'required', 'timezone' => 'timezone', 'currency' => 'size', 'fiscalYearStartMonth' => 'between']);
});

test('uploading a logo attaches it to the company profile media collection', function () {
    Storage::fake('public');

    $this->actingAs(userWithCompanyPermissions());

    Livewire::test(CompanyProfileForm::class)
        ->set('name', 'Golden Info Tech')
        ->set('timezone', 'UTC')
        ->set('currency', 'USD')
        ->set('fiscalYearStartMonth', 1)
        ->set('logo', UploadedFile::fake()->image('logo.png'))
        ->call('save')
        ->assertHasNoErrors();

    expect(Company::current()->logoUrl())->not->toBeNull();
});
