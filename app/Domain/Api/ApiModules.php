<?php

namespace App\Domain\Api;

use App\Domain\Api\Contracts\ApiModule;
use App\Domain\Api\Modules\AccountApi;
use App\Domain\Api\Modules\ContactApi;
use App\Domain\Api\Modules\DealApi;
use App\Domain\Api\Modules\LeadApi;

/**
 * What the REST API exposes.
 *
 * A short, explicit list rather than "every module". An API surface is a
 * promise: everything on it has to keep working, in the shape it went out in,
 * for as long as somebody's integration depends on it. Adding a module here is
 * a decision; making it automatic would mean shipping that promise by accident
 * every time somebody built a screen.
 *
 * Matched by **key**, never by a class name from the request. A registry that
 * resolves a class named in a URL is one typo away from being an arbitrary
 * instantiation.
 */
final class ApiModules
{
    /**
     * @var array<string, class-string<ApiModule>>
     */
    private const MODULES = [
        'accounts' => AccountApi::class,
        'contacts' => ContactApi::class,
        'leads' => LeadApi::class,
        'deals' => DealApi::class,
    ];

    public static function find(string $key): ?ApiModule
    {
        $module = self::MODULES[$key] ?? null;

        return $module === null ? null : app($module);
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::MODULES);
    }
}
