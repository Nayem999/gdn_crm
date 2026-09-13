<?php

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\DTOs\MappedPayload;
use App\Domain\Ingestion\IngestionWriters;
use App\Domain\Ingestion\Models\DataSource;
use App\Domain\Ingestion\PayloadMapper;
use App\Domain\Ingestion\PayloadReader;
use Illuminate\Support\Facades\Validator;

/**
 * What this source's mappings would make of a payload — without making it.
 *
 * **Creates nothing, touches nothing.** It runs the same mapper and the same
 * validation rules the pipeline runs, and stops before the write. That is the
 * whole value: a preview produced by a second implementation is a preview of
 * something else, and the reason anybody trusts a dry run is that it is the
 * real thing with the last step removed.
 */
class DryRunMappingAction
{
    public function __construct(private readonly PayloadMapper $mapper) {}

    /**
     * @return array{mapped: MappedPayload|null, errors: array<int, string>, module: string}
     */
    public function __invoke(DataSource $source, ?string $body = null): array
    {
        $body ??= $source->sample_payload;
        $writer = IngestionWriters::for($source->target_module);

        if ($writer === null) {
            return ['mapped' => null, 'errors' => ['Nothing can write into '.$source->target_module.'.'], 'module' => $source->target_module];
        }

        $payload = $body === null ? null : PayloadReader::decode($body);

        if ($payload === null) {
            return ['mapped' => null, 'errors' => ['There is no sample payload to test against yet.'], 'module' => $source->target_module];
        }

        $mapped = $this->mapper->map($source, $payload, $writer);
        $errors = [];

        foreach ($mapped->missing as $field) {
            $errors[] = 'Required by the mapping but absent from the sample: '.$field.'.';
        }

        foreach ($mapped->ignored as $field) {
            $errors[] = $field.' is mapped but is not a field on '.$source->targetLabel().' any more.';
        }

        if ($mapped->row !== []) {
            // The module's own rules, so the dry run refuses exactly what the
            // pipeline would refuse.
            $validator = Validator::make($mapped->row, $writer->rulesFor(array_keys($mapped->row)));

            foreach ($validator->errors()->all() as $message) {
                $errors[] = (string) $message;
            }
        }

        return ['mapped' => $mapped, 'errors' => $errors, 'module' => $source->target_module];
    }
}
