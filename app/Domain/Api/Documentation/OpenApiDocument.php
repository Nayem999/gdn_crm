<?php

namespace App\Domain\Api\Documentation;

use App\Domain\Api\ApiModules;
use App\Domain\Api\Contracts\ApiModule;
use App\Domain\Webhooks\WebhookEvents;
use App\Domain\Webhooks\WebhookSignature;

/**
 * The API, described from the registry that implements it.
 *
 * **Generated rather than written.** Hand-written API documentation is
 * documentation that was true once: a field gets added, a rule changes, a
 * module joins the surface, and nobody edits the page — so the document says
 * one thing, the API does another, and the integration that trusted it breaks
 * at the customer's end. Here the endpoints come from the registry, the request
 * fields come from the same validation rules the controller enforces, and the
 * response fields come from the declaration a guard test holds against the real
 * output. There is nothing left to forget to update.
 *
 * OpenAPI 3.1, because that is the version with a top-level `webhooks` section
 * — and outbound webhooks are half of what an integrator needs to know.
 */
class OpenApiDocument
{
    public const VERSION = '1.0.0';

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => config('app.name').' API',
                'version' => self::VERSION,
                'description' => $this->description(),
            ],
            'servers' => [
                ['url' => rtrim((string) config('app.url'), '/').'/api/v1'],
            ],
            'security' => [['bearerAuth' => []]],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'A key created under Settings → API keys. It acts as the person who made it and sees exactly what they see.',
                    ],
                ],
                'schemas' => $this->schemas(),
            ],
            'paths' => $this->paths(),
            'webhooks' => $this->webhooks(),
        ];
    }

    private function description(): string
    {
        $limit = (int) settings('api.rate_limit_per_minute', 60);

        return implode("\n\n", [
            'Every request needs a bearer key. A key belongs to a person and carries their permissions and their access level, so two keys made by two people see different records.',
            'Keys are read-only unless they were created with write access; a write to a read-only key answers 403.',
            'Rate limited to '.$limit.' requests per minute **per key**, so one busy integration cannot starve another. Over the limit answers 429.',
            'A record you may not see answers 404 rather than 403 — the API does not confirm that a record exists in order to refuse it.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function schemas(): array
    {
        $schemas = [
            'Error' => [
                'type' => 'object',
                'properties' => ['message' => ['type' => 'string']],
            ],
            'ValidationError' => [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string'],
                    'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ],
            ],
            'Pagination' => [
                'type' => 'object',
                'properties' => [
                    'current_page' => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer'],
                    'total' => ['type' => 'integer'],
                    'last_page' => ['type' => 'integer'],
                ],
            ],
        ];

        foreach ($this->modules() as $key => $module) {
            $schemas[$this->schemaName($key)] = $this->recordSchema($module);
            $schemas[$this->schemaName($key).'Input'] = $this->inputSchema($module);
        }

        return $schemas;
    }

    /**
     * @return array<string, mixed>
     */
    private function recordSchema(ApiModule $module): array
    {
        $properties = [];

        foreach ($module->schema() as $field => $type) {
            $properties[$field] = $this->property($type);
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    /**
     * The writable fields, taken from the very rules the controller validates
     * against — so a field that cannot be written cannot be documented as
     * though it can.
     *
     * @return array<string, mixed>
     */
    private function inputSchema(ApiModule $module): array
    {
        $properties = [];
        $required = [];

        foreach ($module->rules(creating: true) as $field => $rules) {
            $tokens = $this->tokens($rules);

            $properties[$field] = $this->property($this->typeOf($tokens));

            if (in_array('required', $tokens, true)) {
                $required[] = $field;
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @param  mixed  $rules
     * @return array<int, string>
     */
    private function tokens($rules): array
    {
        $tokens = [];

        foreach (is_array($rules) ? $rules : explode('|', (string) $rules) as $rule) {
            if (is_string($rule)) {
                $tokens[] = strtok($rule, ':') ?: $rule;
            }
        }

        return $tokens;
    }

    /**
     * @param  array<int, string>  $tokens
     */
    private function typeOf(array $tokens): string
    {
        return match (true) {
            in_array('integer', $tokens, true) => 'integer',
            in_array('numeric', $tokens, true) => 'number',
            in_array('boolean', $tokens, true) => 'boolean',
            in_array('date', $tokens, true) => 'date',
            in_array('email', $tokens, true) => 'email',
            in_array('url', $tokens, true) => 'url',
            default => 'string',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function property(string $type): array
    {
        return match ($type) {
            'integer' => ['type' => ['integer', 'null']],
            'number' => ['type' => ['number', 'null']],
            'boolean' => ['type' => ['boolean', 'null']],
            'object' => ['type' => ['object', 'null']],
            // A list of assignee objects is the one array-typed field this
            // API currently returns (Lead::assignees) — declared minimally
            // rather than describing each object's own properties, which
            // would need a per-field item schema this generator has no
            // input for yet.
            'array' => ['type' => 'array', 'items' => ['type' => 'object']],
            'date' => ['type' => ['string', 'null'], 'format' => 'date'],
            'date-time' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            'email' => ['type' => ['string', 'null'], 'format' => 'email'],
            'url' => ['type' => ['string', 'null'], 'format' => 'uri'],
            default => ['type' => ['string', 'null']],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function paths(): array
    {
        $paths = [
            '/me' => [
                'get' => [
                    'summary' => 'Who this key belongs to',
                    'description' => 'The first call worth making: it says whose key this is and whether it may write.',
                    'tags' => ['Keys'],
                    'responses' => [
                        '200' => $this->jsonResponse('The key holder.', [
                            'type' => 'object',
                            'properties' => ['data' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'integer'],
                                    'name' => ['type' => 'string'],
                                    'email' => ['type' => 'string', 'format' => 'email'],
                                    'token_name' => ['type' => ['string', 'null']],
                                    'abilities' => ['type' => 'array', 'items' => ['type' => 'string']],
                                ],
                            ]],
                        ]),
                        '401' => $this->errorResponse('No key, or a key that has been revoked.'),
                    ],
                ],
            ],
        ];

        foreach ($this->modules() as $key => $module) {
            $name = $this->schemaName($key);
            $tag = ucfirst($key);

            $paths['/'.$key] = [
                'get' => [
                    'summary' => 'List '.$key,
                    'description' => 'Only the records this key\'s owner may see, newest first.',
                    'tags' => [$tag],
                    'parameters' => [
                        ['name' => 'per_page', 'in' => 'query', 'required' => false,
                            'description' => 'Up to 100. Anything larger is treated as 100.',
                            'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25]],
                    ],
                    'responses' => [
                        '200' => $this->jsonResponse('A page of records.', [
                            'type' => 'object',
                            'properties' => [
                                'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/'.$name]],
                                'meta' => ['$ref' => '#/components/schemas/Pagination'],
                            ],
                        ]),
                        '403' => $this->errorResponse('The key holder cannot view this module.'),
                        '429' => $this->errorResponse('Over the rate limit for this key.'),
                    ],
                ],
                'post' => [
                    'summary' => 'Create a record',
                    'description' => 'Needs a key with write access.',
                    'tags' => [$tag],
                    'requestBody' => $this->jsonBody($name.'Input'),
                    'responses' => [
                        '201' => $this->jsonResponse('The record as created.', $this->dataWrapper($name)),
                        '403' => $this->errorResponse('A read-only key, or no permission to create.'),
                        '422' => $this->validationResponse(),
                    ],
                ],
            ];

            $paths['/'.$key.'/{id}'] = [
                'parameters' => [
                    ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                ],
                'get' => [
                    'summary' => 'Fetch one record',
                    'tags' => [$tag],
                    'responses' => [
                        '200' => $this->jsonResponse('The record.', $this->dataWrapper($name)),
                        '404' => $this->errorResponse('No such record, or not one this key may see.'),
                    ],
                ],
                'patch' => [
                    'summary' => 'Change a record',
                    'description' => 'A partial update: fields you do not send are left alone. Needs write access.',
                    'tags' => [$tag],
                    'requestBody' => $this->jsonBody($name.'Input'),
                    'responses' => [
                        '200' => $this->jsonResponse('The record as changed.', $this->dataWrapper($name)),
                        '403' => $this->errorResponse('A read-only key, or no permission to change this record.'),
                        '404' => $this->errorResponse('No such record, or not one this key may see.'),
                        '422' => $this->validationResponse(),
                    ],
                ],
                'delete' => [
                    'summary' => 'Delete a record',
                    'tags' => [$tag],
                    'responses' => [
                        '204' => ['description' => 'Deleted.'],
                        '403' => $this->errorResponse('A read-only key, or no permission to delete this record.'),
                        '404' => $this->errorResponse('No such record, or not one this key may see.'),
                    ],
                ],
            ];
        }

        return $paths;
    }

    /**
     * The other half of an integration: what we send you, unasked.
     *
     * @return array<string, mixed>
     */
    private function webhooks(): array
    {
        $webhooks = [];

        foreach (WebhookEvents::all() as $event) {
            [$module] = explode('.', $event);

            $webhooks[$event] = [
                'post' => [
                    'summary' => WebhookEvents::options()[$event] ?? $event,
                    'description' => 'Sent to every endpoint subscribed to `'.$event.'`. Verify the `'
                        .WebhookSignature::HEADER.'` header before trusting the body: it is `t=<unix>,v1=<hex>`, '
                        .'where the signature is HMAC-SHA256 of `"{t}.{raw body}"` with the endpoint secret. '
                        .'Reject anything whose timestamp is more than a few minutes old — that is what stops a '
                        .'captured request being replayed. Answer 2xx; anything else is retried for about an hour.',
                    'requestBody' => [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'This delivery. A retry repeats it, so it is how you recognise one.'],
                                'event' => ['type' => 'string', 'const' => $event],
                                'occurred_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'When it happened, not when it was sent.'],
                                'data' => ['$ref' => '#/components/schemas/'.$this->schemaName($module)],
                            ],
                        ]]],
                    ],
                    'responses' => ['200' => ['description' => 'Accepted.']],
                ],
            ];
        }

        return $webhooks;
    }

    /**
     * @return array<string, mixed>
     */
    private function dataWrapper(string $name): array
    {
        return ['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/'.$name]]];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function jsonResponse(string $description, array $schema): array
    {
        return ['description' => $description, 'content' => ['application/json' => ['schema' => $schema]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(string $schemaName): array
    {
        return [
            'required' => true,
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$schemaName]]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResponse(string $description): array
    {
        return $this->jsonResponse($description, ['$ref' => '#/components/schemas/Error']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validationResponse(): array
    {
        return $this->jsonResponse('Something in the body was refused.', ['$ref' => '#/components/schemas/ValidationError']);
    }

    /**
     * @return array<string, ApiModule>
     */
    private function modules(): array
    {
        $modules = [];

        foreach (ApiModules::keys() as $key) {
            $module = ApiModules::find($key);

            if ($module !== null) {
                $modules[$key] = $module;
            }
        }

        return $modules;
    }

    /**
     * "contacts" becomes "Contact": a schema names one record, not a
     * collection.
     */
    private function schemaName(string $key): string
    {
        return ucfirst(rtrim($key, 's'));
    }
}
