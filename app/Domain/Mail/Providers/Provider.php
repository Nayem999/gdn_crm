<?php

namespace App\Domain\Mail\Providers;

use App\Domain\Mail\Contracts\MailProvider;
use App\Domain\Mail\ProviderError;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingField;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

/**
 * The bookkeeping every provider shares: prefixing its field keys with its own
 * key, and reading a credential back out by the short name.
 *
 * Prefixing happens in one place so a provider declares `host` and the registry
 * sees `smtp_host`. An underscore, not a dot: a dotted key would be a nested
 * path to both Livewire's model binding and the validator, and the settings
 * form would quietly write `['smtp' => ['host' => ...]]` instead of the flat
 * key the registry declared.
 */
abstract class Provider implements MailProvider
{
    public function description(): string
    {
        return '';
    }

    /**
     * Open the connection and close it again.
     *
     * For an SMTP provider this is the whole test: the handshake includes
     * authentication, so a wrong password fails here rather than on the first
     * message.
     */
    protected function verifySmtp(SmtpTransport $transport): void
    {
        try {
            $transport->start();
            $transport->stop();
        } catch (Throwable $failure) {
            throw new RuntimeException($failure->getMessage(), previous: $failure);
        }
    }

    /**
     * Ask the provider something harmless and insist on a 2xx.
     */
    protected function verifyEndpoint(PendingRequest $request, string $url): void
    {
        try {
            $response = $request->timeout(15)->get($url);
        } catch (Throwable $failure) {
            throw new RuntimeException($failure->getMessage(), previous: $failure);
        }

        if ($response->failed()) {
            throw new RuntimeException(ProviderError::describe($response, $this->label()));
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    protected function credential(array $credentials, string $key, mixed $default = null): mixed
    {
        $value = $credentials[$this->key().'_'.$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, string>  $required  Short key => what to call it.
     * @return array<int, string>
     */
    protected function missing(array $credentials, array $required): array
    {
        $labels = [];

        foreach ($required as $key => $label) {
            if ($this->credential($credentials, $key) === null) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    protected function text(string $key, string $label, ?string $help = null): SettingField
    {
        return new SettingField($this->prefixed($key), $label, SettingType::String, help: $help, showWhen: $this->onlyWhenActive());
    }

    protected function secret(string $key, string $label, ?string $help = null): SettingField
    {
        return new SettingField($this->prefixed($key), $label, SettingType::String, secret: true, help: $help, showWhen: $this->onlyWhenActive());
    }

    protected function integer(string $key, string $label, ?int $default = null, ?string $help = null): SettingField
    {
        return new SettingField($this->prefixed($key), $label, SettingType::Integer, default: $default, help: $help, showWhen: $this->onlyWhenActive());
    }

    /**
     * @param  array<string, string>  $options
     */
    protected function select(string $key, string $label, array $options, string $default, ?string $help = null): SettingField
    {
        return SettingField::select($this->prefixed($key), $label, $options, $default, $help, showWhen: $this->onlyWhenActive());
    }

    /**
     * A provider's own credentials only apply when it is the one being used —
     * as the active provider or as the fallback behind it. Anything else is a
     * form asking for six providers' credentials at once.
     *
     * @return array<string, array<int, string>>
     */
    private function onlyWhenActive(): array
    {
        return ['provider' => [$this->key()], 'fallback' => [$this->key()]];
    }

    private function prefixed(string $key): string
    {
        return $this->key().'_'.$key;
    }
}
