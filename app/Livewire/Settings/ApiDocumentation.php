<?php

namespace App\Livewire\Settings;

use App\Domain\Api\Documentation\OpenApiDocument;
use App\Domain\Webhooks\WebhookSignature;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The API, readable.
 *
 * The same document the machine-readable route serves, rendered as a page —
 * not a second description written by hand. Two descriptions of one API is one
 * description and one lie waiting to happen.
 */
#[Title('API documentation')]
class ApiDocumentation extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->can('api.tokens') ?? false, 403);
    }

    /**
     * @return array<string, mixed>
     */
    public function document(): array
    {
        // Built once per render: several sections read it.
        return $this->document ??= app(OpenApiDocument::class)->build();
    }

    /**
     * @var array<string, mixed>|null
     */
    private ?array $document = null;

    /**
     * What to send when creating or updating each kind of record: every field
     * the endpoint accepts, whether it is required, and what it allows — read
     * from the same `*Input` schema the API validates against.
     *
     * @return array<string, array{create: string, update: string|null, fields: array<int, array{field: string, type: string, required: bool, notes: string}>, example: string}>
     */
    public function requestShapes(): array
    {
        $document = $this->document();
        $shapes = [];

        foreach ($document['paths'] as $path => $operations) {
            $ref = $operations['post']['requestBody']['content']['application/json']['schema']['$ref'] ?? null;

            if (! is_string($ref)) {
                continue;
            }

            $schemaName = basename($ref);
            $schema = $document['components']['schemas'][$schemaName] ?? null;

            if (! is_array($schema)) {
                continue;
            }

            $required = $schema['required'] ?? [];
            $fields = [];
            $example = [];

            foreach ($schema['properties'] as $field => $property) {
                $types = (array) ($property['type'] ?? ['string']);
                $type = implode(' or ', array_filter($types, fn (string $type): bool => $type !== 'null'))
                    .(isset($property['format']) ? ' ('.$property['format'].')' : '');

                $notes = array_filter([
                    isset($property['maxLength']) ? 'up to '.$property['maxLength'].' characters' : null,
                    isset($property['minimum']) ? 'at least '.$property['minimum'] : null,
                    isset($property['enum']) ? 'one of: '.implode(', ', $property['enum']) : null,
                    isset($property['x-required-without']) ? 'required when '.implode(' and ', $property['x-required-without']).' is not sent' : null,
                ]);

                $fields[] = [
                    'field' => (string) $field,
                    'type' => $type,
                    'required' => in_array($field, $required, true),
                    'notes' => implode('; ', $notes),
                ];

                if (in_array($field, $required, true) || in_array($field, ['last_name', 'email', 'phone', 'name', 'company_name'], true)) {
                    $example[$field] = $this->exampleValue((string) $field, $property);
                }
            }

            $shapes[str_replace('Input', '', $schemaName)] = [
                'create' => 'POST '.$path,
                'update' => isset($document['paths'][$path.'/{id}']['patch']) ? 'PATCH '.$path.'/{id}' : null,
                'fields' => $fields,
                'example' => (string) json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ];
        }

        return $shapes;
    }

    /**
     * @param  array<string, mixed>  $property
     */
    private function exampleValue(string $field, array $property): mixed
    {
        $types = (array) ($property['type'] ?? ['string']);

        return match (true) {
            isset($property['enum']) => $property['enum'][0],
            ($property['format'] ?? null) === 'email' => 'dara@example.com',
            in_array('integer', $types, true) => 1,
            in_array('number', $types, true) => 1000,
            $field === 'first_name' => 'Dara',
            $field === 'last_name' => 'Okafor',
            $field === 'phone' => '+8801711000000',
            $field === 'company_name' => 'Acme Ltd',
            default => 'Example',
        };
    }

    /**
     * The paths, flattened into the rows a person reads: one per method.
     *
     * @return array<int, array{method: string, path: string, summary: string, description: string|null}>
     */
    public function operations(): array
    {
        $rows = [];

        foreach ($this->document()['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (! is_array($operation) || ! isset($operation['summary'])) {
                    continue;
                }

                $rows[] = [
                    'method' => strtoupper($method),
                    'path' => $path,
                    'summary' => $operation['summary'],
                    'description' => $operation['description'] ?? null,
                ];
            }
        }

        return $rows;
    }

    /**
     * The record shapes, without the *Input duplicates and the shared
     * envelopes — those are noise on a page somebody is skimming.
     *
     * @return array<string, array<string, string>>
     */
    public function recordShapes(): array
    {
        $shapes = [];

        foreach ($this->document()['components']['schemas'] as $name => $schema) {
            if (str_ends_with($name, 'Input') || ! isset($schema['properties']) || in_array($name, ['Error', 'ValidationError', 'Pagination'], true)) {
                continue;
            }

            $fields = [];

            foreach ($schema['properties'] as $field => $property) {
                $types = (array) ($property['type'] ?? ['string']);

                $fields[$field] = implode(' or ', array_filter($types, fn (string $type): bool => $type !== 'null'))
                    .(isset($property['format']) ? ' ('.$property['format'].')' : '')
                    .(in_array('null', $types, true) ? ', may be null' : '');
            }

            $shapes[$name] = $fields;
        }

        return $shapes;
    }

    /**
     * @return array<int, string>
     */
    public function webhookEvents(): array
    {
        return array_keys($this->document()['webhooks']);
    }

    public function signatureHeader(): string
    {
        return WebhookSignature::HEADER;
    }

    public function render(): View
    {
        return view('livewire.settings.api-documentation', [
            'info' => $this->document()['info'],
            'server' => $this->document()['servers'][0]['url'],
        ]);
    }
}
