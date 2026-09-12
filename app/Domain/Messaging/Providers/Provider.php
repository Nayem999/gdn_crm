<?php

namespace App\Domain\Messaging\Providers;

use App\Domain\Mail\ProviderError;
use App\Domain\Messaging\Contracts\MessagingProvider;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingField;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The bookkeeping every messaging provider shares.
 *
 * Same arrangement as the mail providers: field keys are prefixed with the
 * provider's own key so that every provider's credentials live side by side in
 * one group and switching between them destroys nothing.
 */
abstract class Provider implements MessagingProvider
{
    public function description(): string
    {
        return '';
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
     * @param  array<string, string>  $required
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

    protected function request(): PendingRequest
    {
        return Http::timeout(20)->connectTimeout(10);
    }

    protected function failed(Response $response): RuntimeException
    {
        return new RuntimeException(ProviderError::describe($response, $this->label()));
    }

    protected function verifyEndpoint(PendingRequest $request, string $url): void
    {
        try {
            $response = $request->timeout(15)->get($url);
        } catch (Throwable $failure) {
            throw new RuntimeException($failure->getMessage(), previous: $failure);
        }

        if ($response->failed()) {
            throw $this->failed($response);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function onlyWhenActive(): array
    {
        return ['provider' => [$this->key()]];
    }

    private function prefixed(string $key): string
    {
        return $this->key().'_'.$key;
    }
}
