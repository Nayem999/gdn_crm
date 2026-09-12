<?php

namespace App\Console\Commands;

use App\Domain\Api\Documentation\OpenApiDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Write the API description to a file.
 *
 * The application serves the same document live, so this is not how anybody
 * reads it — it is how a build proves it can still be produced, and how a
 * pipeline gets a file to publish or to diff against the last release. A
 * generated document that stops generating is a break worth failing a build
 * over, which is why this returns a non-zero status when it cannot.
 */
class GenerateApiDocs extends Command
{
    protected $signature = 'api:docs {--path= : Where to write it; defaults to storage/app/api/openapi.json}';

    protected $description = 'Generate the OpenAPI description of the REST API';

    public function handle(OpenApiDocument $document): int
    {
        $path = (string) ($this->option('path') ?: storage_path('app/api/openapi.json'));

        $json = json_encode($document->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        File::ensureDirectoryExists(dirname($path));

        if (File::put($path, $json.PHP_EOL) === false) {
            $this->error('Could not write '.$path.'.');

            return self::FAILURE;
        }

        $this->info('Wrote '.$path.'.');

        return self::SUCCESS;
    }
}
