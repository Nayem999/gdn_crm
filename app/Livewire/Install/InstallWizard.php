<?php

namespace App\Livewire\Install;

use App\Domain\Company\CompanyOptions;
use App\Domain\Install\Actions\CompleteInstallationAction;
use App\Domain\Install\DTOs\InstallData;
use App\Domain\Install\Installation;
use App\Domain\Mail\MailProviders;
use App\Domain\Settings\SettingField;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * The first-run wizard: company profile, administrator account, email provider.
 *
 * Three steps rather than one long form because the third is genuinely optional
 * — an installation that sends nothing still works — and because a single form
 * that failed validation on a provider credential would throw away the company
 * details somebody had just typed.
 *
 * State lives in the component, so nothing is written until the last step. A
 * wizard that created the administrator at step two and then crashed would
 * leave an installation that is complete enough to lock its own wizard and not
 * complete enough to use.
 */
class InstallWizard extends Component
{
    /** Advanced only by the server, after the step's own rules passed. */
    #[Locked]
    public int $step = 1;

    public const LAST_STEP = 3;

    // -- Step 1: the company ---------------------------------------------------

    public string $companyName = '';

    public string $timezone = 'UTC';

    public string $currency = 'USD';

    public int $fiscalYearStartMonth = 1;

    // -- Step 2: the administrator ---------------------------------------------

    public string $adminName = '';

    public string $adminEmail = '';

    public string $adminPassword = '';

    public string $adminPasswordConfirmation = '';

    // -- Step 3: email ---------------------------------------------------------

    public string $mailProvider = 'log';

    public string $fromAddress = '';

    public string $fromName = '';

    /**
     * The chosen provider's own credentials, keyed by registry key.
     *
     * @var array<string, string>
     */
    public array $mailCredentials = [];

    public function mount(): void
    {
        // The route's middleware has already said so, but a Livewire component
        // is also reachable through /livewire/update, which that middleware
        // never sees. CompleteInstallationAction makes the same check again.
        abort_if(Installation::isComplete(), 404);

        $this->timezone = (string) config('app.timezone', 'UTC');
        $this->companyName = (string) config('app.name');
        $this->fromName = $this->companyName;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rulesForStep(int $step): array
    {
        return match ($step) {
            1 => [
                'companyName' => ['required', 'string', 'max:255'],
                'timezone' => ['required', 'timezone'],
                'currency' => ['required', 'string', 'size:3'],
                'fiscalYearStartMonth' => ['required', 'integer', 'between:1,12'],
            ],
            2 => [
                'adminName' => ['required', 'string', 'max:255'],
                'adminEmail' => ['required', 'email', 'max:255', 'unique:users,email'],
                // Fortify's own rules, so the first account is held to exactly
                // the standard every later one is.
                // confirmed:<field>, because Livewire holds the second box as
                // adminPasswordConfirmation and bare `confirmed` would look for
                // adminPassword_confirmation and pass against nothing.
                'adminPassword' => ['required', 'string', 'confirmed:adminPasswordConfirmation', Password::default()],
            ],
            default => $this->mailRules(),
        };
    }

    /**
     * The chosen provider's required fields, and nothing else's.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function mailRules(): array
    {
        $rules = [
            'mailProvider' => ['required', 'string', 'in:'.implode(',', array_keys(MailProviders::options()))],
            'fromAddress' => ['nullable', 'email', 'max:255'],
            'fromName' => ['nullable', 'string', 'max:255'],
        ];

        foreach ($this->providerFields() as $field) {
            // Nullable, not required: which of a provider's fields are
            // indispensable is the provider's own judgement, checked below.
            $rules['mailCredentials.'.$field->key] = ['nullable', 'string', 'max:500'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [
            'companyName' => 'company name',
            'adminName' => 'name',
            'adminEmail' => 'email address',
            'adminPassword' => 'password',
            'fiscalYearStartMonth' => 'fiscal year start',
        ];

        foreach ($this->providerFields() as $field) {
            $attributes['mailCredentials.'.$field->key] = strtolower($field->label);
        }

        return $attributes;
    }

    /**
     * The same three lists the company profile screen offers, so what you can
     * install with is what you can later change it to.
     *
     * @return array<string, string>
     */
    public function timezoneOptions(): array
    {
        return CompanyOptions::timezones();
    }

    /**
     * @return array<string, string>
     */
    public function currencyOptions(): array
    {
        return CompanyOptions::currencies();
    }

    /**
     * @return array<int, string>
     */
    public function monthOptions(): array
    {
        return CompanyOptions::months();
    }

    /**
     * @return array<string, string>
     */
    public function providerOptions(): array
    {
        return MailProviders::options();
    }

    /**
     * Where to find the chosen provider's credentials, in its own words.
     */
    public function providerDescription(): ?string
    {
        $description = MailProviders::find($this->mailProvider)?->description();

        return $description === '' ? null : $description;
    }

    /**
     * The settings fields belonging to the selected provider.
     *
     * Read from the registry rather than listed here, so a provider added later
     * is configurable in the wizard without anybody remembering to come back.
     *
     * @return array<int, SettingField>
     */
    public function providerFields(): array
    {
        $provider = MailProviders::find($this->mailProvider);

        return $provider === null ? [] : array_values($provider->fields());
    }

    public function updatedMailProvider(): void
    {
        // The credentials on screen belong to the provider that was chosen a
        // moment ago. Keeping them would submit one provider's key under
        // another's name.
        $this->mailCredentials = [];
        $this->resetValidation();
    }

    public function next(): void
    {
        $this->validate($this->rulesForStep($this->step));

        $this->step = min($this->step + 1, self::LAST_STEP);
    }

    public function back(): void
    {
        $this->resetValidation();

        $this->step = max($this->step - 1, 1);
    }

    public function install(CompleteInstallationAction $action): void
    {
        // Every step's rules, not only the last one's: `step` is locked, but
        // the fields behind it are not, and the final submit is the only place
        // that matters.
        foreach ([1, 2, self::LAST_STEP] as $step) {
            $this->validate($this->rulesForStep($step));
        }

        if (($missing = $this->missingMailRequirements()) !== []) {
            $this->addError('mailProvider', 'This provider still needs '.implode(', ', $missing).'.');

            return;
        }

        $data = new InstallData(
            companyName: $this->companyName,
            timezone: $this->timezone,
            currency: $this->currency,
            fiscalYearStartMonth: $this->fiscalYearStartMonth,
            adminName: $this->adminName,
            adminEmail: $this->adminEmail,
            adminPassword: $this->adminPassword,
            mail: $this->mailValues(),
        );

        try {
            $user = $action($data);
        } catch (RuntimeException $exception) {
            $this->addError('step', $exception->getMessage());

            return;
        }

        // Nothing typed here is kept in the component once it has been used:
        // a Livewire snapshot is sent to the browser on every subsequent
        // request, and a provider secret has no business being in one.
        $this->adminPassword = '';
        $this->adminPasswordConfirmation = '';
        $this->mailCredentials = [];

        auth()->login($user);

        $this->redirectRoute('dashboard', navigate: false);
    }

    /**
     * What goes into the `mail` settings group.
     *
     * Blank values are dropped rather than written: a blank from-address means
     * "use whatever the environment file says", and writing an empty string
     * would override that with nothing.
     *
     * @return array<string, mixed>
     */
    protected function mailValues(): array
    {
        $values = [
            'provider' => $this->mailProvider,
            'from_address' => $this->fromAddress,
            'from_name' => $this->fromName,
        ];

        foreach ($this->providerFields() as $field) {
            $values[$field->key] = $this->mailCredentials[$field->key] ?? '';
        }

        return array_filter($values, fn (string $value): bool => trim($value) !== '');
    }

    /**
     * What the chosen provider still needs before it could send anything.
     *
     * Asked of the provider rather than declared here, because "enough to send"
     * is the provider's own rule — SMTP wants a host and a port and will happily
     * relay without a username, while Mailgun needs both its halves.
     *
     * @return array<int, string>
     */
    public function missingMailRequirements(): array
    {
        $provider = MailProviders::find($this->mailProvider);

        return $provider === null ? [] : $provider->missingRequirements($this->mailValues());
    }

    public function render(): View
    {
        // The guest shell, because there is nobody logged in yet and no
        // sidebar to put them in front of — but wider than a sign-in card,
        // since step one is a six-field form.
        return view('livewire.install.install-wizard')
            ->layout('components.layouts.guest', [
                'title' => 'Set up '.config('app.name'),
                'width' => 'max-w-2xl',
            ]);
    }
}
