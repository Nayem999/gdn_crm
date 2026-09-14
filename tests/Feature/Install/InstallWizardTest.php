<?php

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Company\Models\Company;
use App\Domain\Deals\Models\Pipeline;
use App\Domain\Install\Actions\CompleteInstallationAction;
use App\Domain\Install\DTOs\InstallData;
use App\Domain\Install\Installation;
use App\Domain\Settings\SettingsManager;
use App\Livewire\Install\InstallWizard;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Task 11.5 — the first-run wizard.
 *
 * The database starts empty in every test here (RefreshDatabase, and nothing
 * creates a user), which is exactly the state a clean install is in.
 */

/**
 * Fill in all three steps and press the button.
 */
function installWizardCompleted(array $overrides = []): Testable
{
    $values = array_merge([
        'companyName' => 'Golden Infotech',
        'timezone' => 'Asia/Dhaka',
        'currency' => 'BDT',
        'fiscalYearStartMonth' => 7,
        'adminName' => 'Dana Reed',
        'adminEmail' => 'dana@example.com',
        'adminPassword' => 'correct-horse-battery',
        'adminPasswordConfirmation' => 'correct-horse-battery',
        'mailProvider' => 'log',
    ], $overrides);

    return Livewire::test(InstallWizard::class)
        ->set($values)
        ->call('install');
}

// -- Getting in ----------------------------------------------------------------

test('the wizard opens on a clean installation', function () {
    $this->get(route('install'))
        ->assertOk()
        ->assertSee('Tell us about your company');
});

test('every step renders, including the provider credentials of the chosen provider', function () {
    Livewire::test(InstallWizard::class)
        ->assertSee('Tell us about your company')
        ->set('companyName', 'Golden Infotech')
        ->call('next')
        ->assertSee('Create the administrator')
        ->set(['adminName' => 'Dana Reed', 'adminEmail' => 'dana@example.com'])
        ->set('adminPassword', 'correct-horse-battery')
        ->set('adminPasswordConfirmation', 'correct-horse-battery')
        ->call('next')
        ->assertSee('How should email be sent?')
        // The log driver asks for nothing, so nothing is drawn for it.
        ->assertDontSee('SMTP host')
        ->set('mailProvider', 'smtp')
        ->assertSee('SMTP host')
        ->assertSee('SMTP password');
});

test('the sign-in page points at the wizard while the install is pending', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Run the setup wizard');
});

test('the wizard is shut the moment there is an account', function () {
    User::factory()->create();

    $this->get(route('install'))->assertRedirect(route('login'));

    $this->get(route('login'))->assertDontSee('Run the setup wizard');
});

test('the wizard is shut once the marker is written, even with no accounts left', function () {
    Installation::markComplete();

    $this->get(route('install'))->assertRedirect(route('login'));
});

test('the component itself refuses once the installation is complete', function () {
    User::factory()->create();

    // Not only the route: a Livewire component is also reachable through
    // /livewire/update, which the route's middleware never sees.
    Livewire::test(InstallWizard::class)->assertStatus(404);
});

// -- Walking the steps ---------------------------------------------------------

test('a step will not advance until its own fields are valid', function () {
    Livewire::test(InstallWizard::class)
        ->set('companyName', '')
        ->call('next')
        ->assertHasErrors(['companyName' => 'required'])
        ->assertSet('step', 1);
});

test('a valid step advances, and back returns to it with the answers kept', function () {
    Livewire::test(InstallWizard::class)
        ->set('companyName', 'Golden Infotech')
        ->call('next')
        ->assertSet('step', 2)
        ->call('back')
        ->assertSet('step', 1)
        ->assertSet('companyName', 'Golden Infotech');
});

test('the step cannot be moved from the browser', function () {
    // Locked, so a snapshot edited to say "step 3" cannot skip the two forms
    // in front of it. install() revalidates all three regardless.
    expect(fn () => Livewire::test(InstallWizard::class)->set('step', 3))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('the administrator step holds the first account to the standard password rules', function () {
    Livewire::test(InstallWizard::class)
        ->set('companyName', 'Golden Infotech')
        ->call('next')
        ->set(['adminName' => 'Dana Reed', 'adminEmail' => 'dana@example.com'])
        ->set('adminPassword', 'short')
        ->set('adminPasswordConfirmation', 'short')
        ->call('next')
        ->assertHasErrors('adminPassword');
});

test('nothing is written until the last step', function () {
    Livewire::test(InstallWizard::class)
        ->set('companyName', 'Golden Infotech')
        ->call('next')
        ->set(['adminName' => 'Dana Reed', 'adminEmail' => 'dana@example.com'])
        ->set('adminPassword', 'correct-horse-battery')
        ->set('adminPasswordConfirmation', 'correct-horse-battery')
        ->call('next')
        ->assertSet('step', 3);

    expect(User::query()->count())->toBe(0)
        ->and(Installation::isPending())->toBeTrue();
});

test('the final submit revalidates every step, not only the last one', function () {
    // `step` is locked, but the fields behind it are not: a snapshot edited to
    // blank the company name must not install anything.
    installWizardCompleted(['companyName' => ''])
        ->assertHasErrors('companyName');

    expect(User::query()->count())->toBe(0);
});

// -- Finishing -----------------------------------------------------------------

test('finishing creates the company profile', function () {
    installWizardCompleted();

    $company = Company::current();

    expect($company->name)->toBe('Golden Infotech')
        ->and($company->timezone)->toBe('Asia/Dhaka')
        ->and($company->currency)->toBe('BDT')
        ->and($company->fiscal_year_start_month)->toBe(7)
        ->and($company->installed_at)->not->toBeNull();
});

test('finishing creates an administrator holding every permission', function () {
    installWizardCompleted();

    $user = User::query()->firstWhere('email', 'dana@example.com');

    expect($user)->not->toBeNull()
        ->and($user->hasRole(PermissionCatalogue::SUPER_ADMIN_ROLE))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();

    // By role, never by direct grant: a directly granted account stops at
    // whatever the catalogue held on the day it was made.
    expect($user->getDirectPermissions())->toBeEmpty()
        ->and($user->can('accounts.view'))->toBeTrue()
        ->and($user->can('settings.update'))->toBeTrue();
});

test('finishing seeds the baseline an installation cannot work without', function () {
    installWizardCompleted();

    expect(Role::query()->where('name', PermissionCatalogue::SUPER_ADMIN_ROLE)->exists())->toBeTrue()
        ->and(Pipeline::query()->count())->toBeGreaterThan(0)
        ->and(Pipeline::default())->not->toBeNull();
});

test('finishing signs the administrator in and lands on the dashboard', function () {
    installWizardCompleted()->assertRedirect(route('dashboard'));

    expect(auth()->check())->toBeTrue()
        ->and(auth()->user()->email)->toBe('dana@example.com');

    $this->get(route('dashboard'))->assertOk();
});

test('the password never stays in the component once it has been used', function () {
    // The snapshot travels to the browser on every later request.
    installWizardCompleted()
        ->assertSet('adminPassword', '')
        ->assertSet('adminPasswordConfirmation', '')
        ->assertSet('mailCredentials', []);
});

test('installing twice is refused by the action, not only by the screen', function () {
    installWizardCompleted();

    $second = fn () => app(CompleteInstallationAction::class)(new InstallData(
        companyName: 'Someone Else Ltd',
        timezone: 'UTC',
        currency: 'USD',
        fiscalYearStartMonth: 1,
        adminName: 'Mallory',
        adminEmail: 'mallory@example.com',
        adminPassword: 'correct-horse-battery',
    ));

    expect($second)->toThrow(RuntimeException::class);
    expect(User::query()->count())->toBe(1);
});

// -- Email ---------------------------------------------------------------------

test('the log provider asks for nothing and finishes', function () {
    installWizardCompleted(['mailProvider' => 'log'])->assertHasNoErrors();

    expect(app(SettingsManager::class)->get('mail.provider'))->toBe('log');
});

test('a provider that is missing what it needs will not install', function () {
    installWizardCompleted(['mailProvider' => 'smtp'])
        ->assertHasErrors('mailProvider');

    expect(User::query()->count())->toBe(0);
});

test('provider credentials are stored through the settings registry', function () {
    installWizardCompleted([
        'mailProvider' => 'smtp',
        'mailCredentials' => [
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '587',
            'smtp_username' => 'crm@example.com',
            'smtp_password' => 'super-secret',
        ],
        'fromAddress' => 'crm@example.com',
        'fromName' => 'Golden CRM',
    ])->assertHasNoErrors();

    $settings = app(SettingsManager::class);

    expect($settings->get('mail.provider'))->toBe('smtp')
        ->and($settings->get('mail.smtp_host'))->toBe('smtp.example.com')
        ->and($settings->get('mail.from_address'))->toBe('crm@example.com');
});

test('a stored provider secret is encrypted at rest', function () {
    installWizardCompleted([
        'mailProvider' => 'smtp',
        'mailCredentials' => [
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '587',
            'smtp_password' => 'super-secret',
        ],
    ])->assertHasNoErrors();

    $stored = DB::table('settings')->where('group', 'mail')->where('key', 'smtp_password')->value('value');

    expect($stored)->not->toBe('super-secret')
        ->and(app(SettingsManager::class)->get('mail.smtp_password'))->toBe('super-secret');
});

test('changing provider drops the credentials typed for the previous one', function () {
    Livewire::test(InstallWizard::class)
        ->set('mailProvider', 'smtp')
        ->set('mailCredentials.smtp_host', 'smtp.example.com')
        ->set('mailProvider', 'mailgun')
        ->assertSet('mailCredentials', []);
});

test('a blank from-address is not written over the environment default', function () {
    installWizardCompleted(['fromAddress' => '', 'fromName' => ''])->assertHasNoErrors();

    expect(app(SettingsManager::class)->isSet('mail.from_address'))->toBeFalse();
});
