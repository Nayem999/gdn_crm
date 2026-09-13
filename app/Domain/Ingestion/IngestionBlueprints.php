<?php

namespace App\Domain\Ingestion;

use App\Domain\Ingestion\Enums\DataSourceType;
use App\Domain\Ingestion\Enums\DedupeAction;
use App\Domain\Ingestion\Enums\PayloadFilterOperator;

/**
 * Ready-made source configurations for integrations people actually build.
 *
 * The reference implementation the brief asks for, as a **first-class feature
 * rather than a seeder nobody runs**: an administrator picks one when creating
 * a source and gets the filter, the mappings, the matching rules and a sample
 * payload already filled in, then edits from there.
 *
 * That is worth more than an example in the documentation, because an example
 * in the documentation is an example that stops being true. This one is created
 * by the same actions the screen uses and exercised end to end by the tests, so
 * a change that breaks it breaks the build.
 *
 * A blueprint is a **starting point, not a contract**. Nothing keeps a source in
 * step with the blueprint it came from, on purpose: the whole reason somebody
 * picks one is to then change it.
 */
final class IngestionBlueprints
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'project_task' => self::projectTask(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::all() as $key => $blueprint) {
            $options[$key] = $blueprint['label'];
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * A task or project raised in another system becomes a lead.
     *
     * The example the brief names: their delivery tool creates work, and the
     * person who asked for it is somebody worth selling to. Everything here is
     * shaped by what that kind of system actually sends — an event name to
     * filter on, a nested requester, one full name where we keep two, and their
     * own id so a second delivery about the same task updates rather than
     * duplicates.
     *
     * @return array<string, mixed>
     */
    private static function projectTask(): array
    {
        return [
            'label' => 'Project or task becomes a lead',
            'description' => 'An external project system posts a task when one is raised, and the person who asked for it becomes a lead.',
            'target_module' => 'leads',
            'type' => DataSourceType::Push,

            // Only the events that mean "somebody new wants something". A tool
            // like this also posts comments, status changes and deletions, and
            // every one of those would otherwise become a lead.
            'filters' => [
                ['path' => 'event', 'operator' => PayloadFilterOperator::Equals, 'value' => 'task.created'],
            ],

            'external_id_path' => 'task.id',
            'dedupe_fields' => ['email'],
            'dedupe_action' => DedupeAction::Update,

            'mappings' => [
                // One name where we keep two.
                ['path' => 'task.requester.name', 'field' => 'first_name', 'transform' => 'name_first', 'required' => true],
                ['path' => 'task.requester.name', 'field' => 'last_name', 'transform' => 'name_last'],
                ['path' => 'task.requester.email', 'field' => 'email', 'required' => true],
                ['path' => 'task.requester.phone', 'field' => 'phone', 'transform' => 'digits'],
                ['path' => 'task.project.client', 'field' => 'company_name'],
                ['path' => 'task.title', 'field' => 'description'],
                // Their vocabulary into ours, and a default so a lead always
                // says where it came from even when they send nothing.
                [
                    'path' => 'task.origin',
                    'field' => 'source',
                    'transform' => 'value_map',
                    'options' => [
                        'map' => ['web' => 'web_form', 'form' => 'web_form', 'phone' => 'cold_call', 'referral' => 'referral'],
                        'fallback' => 'partner',
                    ],
                    'default' => 'partner',
                ],
            ],

            // A real example of what that system sends, so the mapping screen
            // has something to show before a single delivery has arrived.
            'sample' => [
                'event' => 'task.created',
                'task' => [
                    'id' => 'TASK-4192',
                    'title' => 'Replace the roof lantern',
                    'origin' => 'web',
                    'project' => ['id' => 'PRJ-88', 'client' => 'Okafor Construction'],
                    'requester' => [
                        'name' => 'Dara Okafor',
                        'email' => 'dara@okafor-construction.test',
                        'phone' => '+44 (0) 1392 555 010',
                    ],
                ],
            ],
        ];
    }
}
