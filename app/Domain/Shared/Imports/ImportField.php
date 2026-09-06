<?php

namespace App\Domain\Shared\Imports;

/**
 * One field a module will accept from a file.
 *
 * Only fields declared here can be mapped to, so a tampered mapping cannot
 * write a column the module never offered — the same registry idea as
 * PermissionCatalogue and DuplicateSource::mergeableFields().
 */
readonly class ImportField
{
    /**
     * @param  array<int, string>  $rules  Laravel rules applied per row.
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $rules = [],
        public bool $required = false,
        public ?string $hint = null,
    ) {}

    /**
     * @param  array<int, string>  $rules
     */
    public static function required(string $key, string $label, array $rules = [], ?string $hint = null): self
    {
        return new self($key, $label, ['required', ...$rules], true, $hint);
    }

    /**
     * @param  array<int, string>  $rules
     */
    public static function optional(string $key, string $label, array $rules = [], ?string $hint = null): self
    {
        return new self($key, $label, ['nullable', ...$rules], false, $hint);
    }
}
