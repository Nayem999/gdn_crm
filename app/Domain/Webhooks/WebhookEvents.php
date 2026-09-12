<?php

namespace App\Domain\Webhooks;

use App\Domain\Api\ApiModules;

/**
 * The events an endpoint can subscribe to.
 *
 * Built from the modules the REST API exposes, deliberately. The payload of a
 * webhook *is* the API's representation of the record, so an integration learns
 * one shape rather than two — and an event cannot exist for a module whose
 * shape has never been decided.
 */
final class WebhookEvents
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    /**
     * @return array<int, string>
     */
    public static function actions(): array
    {
        return [self::CREATED, self::UPDATED, self::DELETED];
    }

    /**
     * Every event key, e.g. "contacts.created".
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $events = [];

        foreach (ApiModules::keys() as $module) {
            foreach (self::actions() as $action) {
                $events[] = $module.'.'.$action;
            }
        }

        return $events;
    }

    /**
     * @return array<string, string> Event key => label, for the picker.
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::all() as $event) {
            [$module, $action] = explode('.', $event);

            $options[$event] = ucfirst($module).' '.$action;
        }

        return $options;
    }

    public static function exists(string $event): bool
    {
        return in_array($event, self::all(), true);
    }

    public static function keyFor(string $module, string $action): string
    {
        return $module.'.'.$action;
    }
}
