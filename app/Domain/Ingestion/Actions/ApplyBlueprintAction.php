<?php

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\Enums\DedupeAction;
use App\Domain\Ingestion\Enums\PayloadFilterOperator;
use App\Domain\Ingestion\IngestionBlueprints;
use App\Domain\Ingestion\Models\DataSource;
use RuntimeException;

/**
 * Fills a source in from one of the ready-made configurations.
 *
 * Everything it writes goes through the same columns the screens edit, so what
 * a blueprint produces is a source somebody could have built by hand — and
 * having built it, can change freely. Nothing keeps the two in step afterwards,
 * which is the point.
 *
 * **Replaces** the source's filters and mappings rather than adding to them. A
 * blueprint applied to a source that already had rules would otherwise leave a
 * mixture of two configurations, which is worse than either.
 */
class ApplyBlueprintAction
{
    /**
     * @throws RuntimeException when the key names no blueprint
     */
    public function __invoke(DataSource $source, string $key): DataSource
    {
        $blueprint = IngestionBlueprints::find($key);

        if ($blueprint === null) {
            // Matched against the registry, never turned into a class or a
            // file name — the same rule every other registry here follows.
            throw new RuntimeException('There is no such template.');
        }

        $source->forceFill([
            'target_module' => $blueprint['target_module'],
            'external_id_path' => $blueprint['external_id_path'] ?? null,
            'dedupe_fields' => $blueprint['dedupe_fields'] ?? null,
            'dedupe_action' => ($blueprint['dedupe_action'] ?? DedupeAction::Update)->value,
            // A sample so the mapping screen has something to show before a
            // single delivery has arrived.
            'sample_payload' => isset($blueprint['sample']) ? json_encode($blueprint['sample'], JSON_PRETTY_PRINT) : null,
            'sample_captured_at' => isset($blueprint['sample']) ? now() : null,
        ])->save();

        $source->filters()->delete();
        $source->mappings()->delete();

        foreach (array_values($blueprint['filters'] ?? []) as $position => $filter) {
            $source->filters()->create([
                'path' => $filter['path'],
                'operator' => ($filter['operator'] ?? PayloadFilterOperator::Equals)->value,
                'value' => $filter['value'] ?? null,
                'position' => $position,
            ]);
        }

        foreach (array_values($blueprint['mappings'] ?? []) as $position => $mapping) {
            $source->mappings()->create([
                'source_path' => $mapping['path'],
                'target_field' => $mapping['field'],
                'is_custom_field' => false,
                'transform' => $mapping['transform'] ?? null,
                'transform_options' => $mapping['options'] ?? null,
                'default_value' => $mapping['default'] ?? null,
                'is_required' => (bool) ($mapping['required'] ?? false),
                'position' => $position,
            ]);
        }

        return $source->refresh();
    }
}
