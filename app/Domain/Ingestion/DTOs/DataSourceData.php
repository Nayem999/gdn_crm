<?php

namespace App\Domain\Ingestion\DTOs;

use App\Domain\Ingestion\Enums\DataSourceType;

/**
 * The fields a create or update carries, already validated.
 *
 * `uuid` is deliberately absent: it is minted once by the model and is the
 * address an outside system has already been given, so no form and no payload
 * may set or change it. The secret columns 8.2 adds are absent for the same
 * reason — they will have an action of their own.
 */
readonly class DataSourceData
{
    public function __construct(
        public string $name,
        public string $targetModule,
        public DataSourceType $type = DataSourceType::Push,
        public ?string $description = null,
        public bool $isActive = true,
        public bool $isSandbox = false,
        public bool $requiresKey = true,
        public bool $requiresSignature = true,
        /** @var array<int, string> */
        public array $ipAllowlist = [],
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        $text = fn (string $key): ?string => match (true) {
            ! array_key_exists($key, $attributes) => null,
            $attributes[$key] === null || trim((string) $attributes[$key]) === '' => null,
            default => trim((string) $attributes[$key]),
        };

        return new self(
            name: trim((string) ($attributes['name'] ?? '')),
            targetModule: (string) ($attributes['target_module'] ?? ''),
            type: DataSourceType::tryFrom((string) ($attributes['type'] ?? '')) ?? DataSourceType::Push,
            description: $text('description'),
            isActive: (bool) ($attributes['is_active'] ?? false),
            isSandbox: (bool) ($attributes['is_sandbox'] ?? false),
            // Default true when absent, not false: an older caller that does
            // not know about these must produce the *safe* arrangement, not the
            // open one.
            requiresKey: (bool) ($attributes['requires_key'] ?? true),
            requiresSignature: (bool) ($attributes['requires_signature'] ?? true),
            ipAllowlist: array_values(array_filter(
                array_map('strval', (array) ($attributes['ip_allowlist'] ?? [])),
                fn (string $entry) => trim($entry) !== ''
            )),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type->value,
            'target_module' => $this->targetModule,
            'is_active' => $this->isActive,
            'is_sandbox' => $this->isSandbox,
            'requires_key' => $this->requiresKey,
            'requires_signature' => $this->requiresSignature,
            'ip_allowlist' => $this->ipAllowlist === [] ? null : $this->ipAllowlist,
        ];
    }
}
