<?php

namespace App\Domain\Ingestion;

use App\Domain\CustomFields\Models\CustomField;
use App\Domain\Ingestion\DTOs\MappedPayload;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\Writers\ImportBackedWriter;

/**
 * Turns a payload into the values a record would be made from.
 *
 * One implementation, used by the pipeline that persists and by the dry run on
 * the mapping screen. A preview computed by a second implementation is a
 * preview of something else, and the whole point of the dry run is that it
 * tells you what will happen.
 */
class PayloadMapper
{
    public function __construct(private readonly ValueTransformer $transformer) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function map(DataSource $source, array $payload, ImportBackedWriter $writer): MappedPayload
    {
        $declared = $writer->fields();
        $customKeys = $this->customFieldKeys($source);

        $row = [];
        $custom = [];
        $missing = [];
        $ignored = [];

        foreach ($source->mappings as $mapping) {
            $target = $mapping->target_field;

            // Checked against what the module declares **every time**, not only
            // when the mapping was saved. A module that drops a field leaves
            // mappings naming something that no longer exists, and writing one
            // would be writing an unknown column.
            $known = $mapping->is_custom_field
                ? in_array($target, $customKeys, true)
                : array_key_exists($target, $declared);

            if (! $known) {
                $ignored[] = $target;

                continue;
            }

            $value = PayloadReader::value($payload, $mapping->source_path);
            $value = $this->transformer->apply($mapping->transform, $value, $mapping->transform_options);

            if ($value === null || $value === '') {
                $value = $mapping->default_value;
            }

            if (($value === null || $value === '') && $mapping->is_required) {
                $missing[] = $target;

                continue;
            }

            if ($value === null) {
                continue;
            }

            $text = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

            $mapping->is_custom_field
                ? $custom[$target] = $text
                : $row[$target] = $text;
        }

        return new MappedPayload($row, $custom, $missing, $ignored);
    }

    /**
     * The custom fields defined for this source's target module.
     *
     * Read from the definitions rather than trusted from the mapping, for the
     * same reason the module's own fields are: a custom field somebody deleted
     * leaves mappings pointing at nothing.
     *
     * @return array<int, string>
     */
    public function customFieldKeys(DataSource $source): array
    {
        /** @var array<int, string> $keys */
        $keys = CustomField::query()
            ->where('module', $source->target_module)
            ->where('is_active', true)
            ->pluck('key')
            ->all();

        return $keys;
    }
}
