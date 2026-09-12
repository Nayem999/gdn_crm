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
        return app(OpenApiDocument::class)->build();
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
