<?php

use App\Domain\Settings\SettingsManager;

if (! function_exists('settings')) {
    /**
     * Read a setting by its dotted name, or get the manager itself when called
     * with no arguments.
     *
     * settings('storage.driver')          -> the stored value, cast
     * settings('storage.s3_key')          -> the decrypted secret
     * settings()->set('storage.driver', 's3')
     */
    function settings(?string $name = null, mixed $default = null): mixed
    {
        $manager = app(SettingsManager::class);

        return $name === null ? $manager : $manager->get($name, $default);
    }
}
