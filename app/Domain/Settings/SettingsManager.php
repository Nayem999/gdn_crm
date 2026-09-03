<?php

namespace App\Domain\Settings;

use App\Domain\Settings\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * The typed accessor behind settings('storage.s3_key').
 *
 * Reads go through a per-group cache. What is cached is the *stored* text, which
 * for a secret means ciphertext — caching the decrypted value would write the
 * plaintext into the cache store (the database, here) and undo encryption at
 * rest. Decryption happens after the cache read, per call.
 */
class SettingsManager
{
    public const CACHE_PREFIX = 'settings:group:';

    /**
     * Stored rows per group for this request, so one page render does not hit
     * the cache store once per setting.
     *
     * @var array<string, array<string, array{value: string|null, secret: bool}>>
     */
    private array $loaded = [];

    /**
     * Read one setting by its dotted name. Undeclared names return the default
     * rather than reaching the database.
     */
    public function get(string $name, mixed $default = null): mixed
    {
        $field = SettingsRegistry::find($name);

        if ($field === null) {
            return $default;
        }

        /** @var array{0: string, 1: string} $parts */
        $parts = SettingsRegistry::split($name);
        $stored = $this->group($parts[0])[$parts[1]] ?? null;

        if ($stored === null || $stored['value'] === null) {
            return $default ?? $field->default;
        }

        $raw = $field->secret ? $this->decrypt($stored['value']) : $stored['value'];

        if ($raw === null) {
            return $default ?? $field->default;
        }

        return $field->type->cast($raw);
    }

    /**
     * Whether a secret has been set, without revealing it. This is what the UI
     * asks so it can show the dots-and-Replace control.
     */
    public function isSet(string $name): bool
    {
        $parts = SettingsRegistry::split($name);

        if ($parts === null) {
            return false;
        }

        $stored = $this->group($parts[0])[$parts[1]] ?? null;

        return $stored !== null && $stored['value'] !== null && $stored['value'] !== '';
    }

    /**
     * Every setting in a group, cast and with defaults filled in.
     *
     * Secrets are deliberately excluded: this feeds screens and templates, and
     * a secret has no business being handed to either. Read one by name when a
     * driver genuinely needs it.
     *
     * @return array<string, mixed>
     */
    public function forGroup(string $group): array
    {
        $values = [];

        foreach (SettingsRegistry::fields($group) as $key => $field) {
            if ($field->secret) {
                continue;
            }

            $values[$key] = $this->get($group.'.'.$key);
        }

        return $values;
    }

    /**
     * Write one setting. Undeclared names are refused rather than created, and
     * the row's type and secrecy always come from the registry, never a caller.
     */
    public function set(string $name, mixed $value): bool
    {
        $field = SettingsRegistry::find($name);

        if ($field === null) {
            return false;
        }

        /** @var array{0: string, 1: string} $parts */
        $parts = SettingsRegistry::split($name);

        $serialised = $field->type->serialise($value);

        Setting::query()->updateOrCreate(
            ['group' => $parts[0], 'key' => $parts[1]],
            [
                'value' => $serialised === null || ! $field->secret ? $serialised : Crypt::encryptString($serialised),
                'type' => $field->type->value,
                'is_secret' => $field->secret,
            ]
        );

        $this->flush($parts[0]);

        return true;
    }

    public function forget(string $name): void
    {
        $parts = SettingsRegistry::split($name);

        if ($parts === null) {
            return;
        }

        Setting::query()->where('group', $parts[0])->where('key', $parts[1])->delete();

        $this->flush($parts[0]);
    }

    /**
     * Drop a group's cache entry. The cache store is the database driver, which
     * has no tag support, so invalidation is by explicit key.
     */
    public function flush(?string $group = null): void
    {
        if ($group === null) {
            foreach (SettingsRegistry::groupKeys() as $key) {
                $this->flush($key);
            }

            return;
        }

        unset($this->loaded[$group]);
        Cache::forget(self::CACHE_PREFIX.$group);
    }

    /**
     * @return array<string, array{value: string|null, secret: bool}>
     */
    private function group(string $group): array
    {
        return $this->loaded[$group] ??= Cache::rememberForever(
            self::CACHE_PREFIX.$group,
            fn (): array => Setting::query()
                ->where('group', $group)
                ->get()
                ->mapWithKeys(fn (Setting $setting) => [
                    $setting->key => ['value' => $setting->value, 'secret' => $setting->is_secret],
                ])
                ->all()
        );
    }

    /**
     * A secret that cannot be decrypted — a rotated APP_KEY, a hand-edited row —
     * reads as absent rather than throwing in the middle of a request.
     */
    private function decrypt(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }
}
